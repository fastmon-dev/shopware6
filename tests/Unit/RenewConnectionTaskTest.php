<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use DateTimeImmutable;
use Fastmon\Collector\Api\FastmonOAuthClient;
use Fastmon\Collector\Connection\AccessTokenProvider;
use Fastmon\Collector\Connection\ConnectionStore;
use Fastmon\Collector\ScheduledTask\RenewConnectionTask;
use Fastmon\Collector\ScheduledTask\RenewConnectionTaskHandler;
use Fastmon\Collector\Service\ConfigResolver;
use Fastmon\Collector\Tests\Unit\Fake\StoresConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

class RenewConnectionTaskTest extends TestCase
{
    use StoresConnection;

    private const BASE = 'https://api.fastmon.eu';

    public function testItRunsWeeklyBecauseTheWindowItProtectsIsSixtyDays(): void
    {
        // Eight times shorter than the expiry it exists for, so a worker that was down
        // for a month costs nothing.
        self::assertSame(604800, RenewConnectionTask::getDefaultInterval());
        self::assertSame('fastmon_collector.renew_connection', RenewConnectionTask::getTaskName());
    }

    public function testItSpendsTheRefreshTokenToBuyTheNextOne(): void
    {
        $this->connected();

        $this->handler([$this->discovery(), $this->tokenResponse('fmt_new', 'fmr_new')])->run();

        self::assertSame('fmr_new', $this->row['refreshToken']);
    }

    public function testItRenewsEvenWhenTheAccessTokenIsStillFresh(): void
    {
        // The task exists to reset the sixty-day clock on the refresh token. Skipping
        // because the access token happens to be young would leave that clock running
        // from whenever somebody last opened the panel.
        $this->connected();
        $this->row['accessTokenExpiresAt'] = new DateTimeImmutable('@' . (time() + 900));

        $this->handler([$this->discovery(), $this->tokenResponse('fmt_new', 'fmr_new')])->run();

        self::assertSame('fmr_new', $this->row['refreshToken']);
    }

    public function testAPastedKeyHasNothingToRenew(): void
    {
        // It does not expire and there is nothing to rotate. No HTTP response is queued,
        // so a request going out would fail the test.
        $this->row = ['manualToken' => 'fmo_key'];

        $this->handler([])->run();

        self::assertSame('fmo_key', $this->row['manualToken']);
    }

    public function testAShopWithNoConnectionIsLeftAlone(): void
    {
        $this->handler([])->run();

        self::assertSame([], $this->row);
    }

    public function testAFailureIsLoggedRatherThanFailingTheTask(): void
    {
        // A shop that could not reach fastmon this week tries again next week with a
        // token that is still valid for weeks. A red entry in the task list would be
        // about a connection that is fine.
        $this->connected();

        $this->handler([new MockResponse('', ['http_code' => 503])])->run();

        self::assertSame('fmr_live', $this->row['refreshToken']);
    }

    public function testAConnectionFastmonEndedIsDroppedRatherThanRetriedForever(): void
    {
        $this->connected();

        $this->handler([
            $this->discovery(),
            new MockResponse(json_encode(['error' => 'invalid_grant'], \JSON_THROW_ON_ERROR), ['http_code' => 400]),
        ])->run();

        self::assertNull($this->row['refreshToken'] ?? null);
    }

    private function connected(): void
    {
        $this->row = [
            'clientId' => 'dyn_1',
            'refreshToken' => 'fmr_live',
            'scopes' => 'org:read app:read app:write site:read',
            'accessTokenExpiresAt' => new DateTimeImmutable('@' . (time() - 60)),
            'accessToken' => 'fmt_old',
        ];
    }

    private function discovery(): MockResponse
    {
        return new MockResponse(json_encode([
            'issuer' => self::BASE,
            'authorization_endpoint' => self::BASE . '/auth/app/authorize',
            'token_endpoint' => self::BASE . '/auth/app/token',
            'registration_endpoint' => self::BASE . '/auth/app/register',
        ], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }

    private function tokenResponse(string $access, string $refresh): MockResponse
    {
        return new MockResponse(json_encode([
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_in' => 900,
            'scope' => 'org:read app:read app:write site:read',
        ], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function handler(array $responses): RenewConnectionTaskHandler
    {
        $systemConfig = $this->systemConfig();
        $store = new ConnectionStore($this->connectionRepository(), $systemConfig);
        $config = new ConfigResolver($systemConfig);

        return new RenewConnectionTaskHandler(
            $this->createMock(EntityRepository::class),
            new NullLogger(),
            new AccessTokenProvider(
                new FastmonOAuthClient(new MockHttpClient($responses)),
                $store,
                $config,
                new LockFactory(new InMemoryStore()),
                new NullLogger(),
            ),
        );
    }
}
