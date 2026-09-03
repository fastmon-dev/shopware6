<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use DateTimeImmutable;
use Fastmon\Collector\Api\FastmonCredentialExpiredException;
use Fastmon\Collector\Api\FastmonOAuthClient;
use Fastmon\Collector\Api\FastmonUnauthorizedException;
use Fastmon\Collector\Connection\AccessTokenProvider;
use Fastmon\Collector\Connection\ConnectionStore;
use Fastmon\Collector\Service\ConfigResolver;
use Fastmon\Collector\Tests\Unit\Fake\StoresConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

class AccessTokenProviderTest extends TestCase
{
    use StoresConnection;

    private const BASE = 'https://api.fastmon.eu';

    public function testAFreshAccessTokenIsUsedAsItIs(): void
    {
        // A refresh token works exactly once, so refreshing when there was no need is not
        // merely wasteful - it is a spent token for nothing.
        $this->connected('fmt_live', expiresIn: 600);

        self::assertSame('fmt_live', $this->provider([])->token());
    }

    public function testAnExpiringTokenIsRotatedAndTheSuccessorStored(): void
    {
        $this->connected('fmt_old', expiresIn: 30);

        $token = $this->provider([
            $this->discovery(),
            $this->tokenResponse('fmt_new', 'fmr_new'),
        ])->token();

        self::assertSame('fmt_new', $token);
        self::assertSame('fmr_new', $this->row['refreshToken']);
        self::assertSame('fmt_new', $this->row['accessToken']);
    }

    public function testARotationTouchesNothingThePageCacheDependsOn(): void
    {
        // A rotated token changes nothing a visitor can see, and it happens whenever
        // somebody works in the administration while the stored access token has aged
        // out. It lands in the connection row, which no cached page is tagged with.
        // While these fields were configuration keys, every rotation risked rebuilding
        // the shop's entire page cache.
        $this->connected('fmt_old', expiresIn: 30);

        $untouched = $this->config;

        $this->provider([$this->discovery(), $this->tokenResponse('fmt_new', 'fmr_new')])->token();

        self::assertSame('fmr_new', $this->row['refreshToken']);
        self::assertSame($untouched, $this->config, 'a token rotation must not write system_config at all');
    }

    public function testARefreshIsDueBeforeTheTokenActuallyExpires(): void
    {
        // Removes the case where a token is checked, found valid, and has expired by the
        // time fastmon reads it. At a fifteen-minute lifetime, spending a minute of it
        // costs nothing.
        $this->connected('fmt_old', expiresIn: 45);

        self::assertSame(
            'fmt_new',
            $this->provider([$this->discovery(), $this->tokenResponse('fmt_new', 'fmr_new')])->token()
        );
    }

    public function testThePairArrivesInOneStatement(): void
    {
        // A process that dies mid-write must never leave the shop holding a spent
        // refresh token: presenting one is what fastmon reads as theft, and it ends the
        // connection. One row and one upsert means there is no window between the two
        // halves at all. While these were separate configuration keys, writing the
        // successor first was what stood in for this.
        $this->connected('fmt_old', expiresIn: 0);

        $this->provider([$this->discovery(), $this->tokenResponse('fmt_new', 'fmr_new')])->token();

        $carryingTheRefreshToken = array_values(array_filter(
            $this->writes,
            static fn (array $payload): bool => \array_key_exists('refreshToken', $payload)
        ));

        self::assertCount(1, $carryingTheRefreshToken);
        self::assertSame('fmr_new', $carryingTheRefreshToken[0]['refreshToken']);
        self::assertSame('fmt_new', $carryingTheRefreshToken[0]['accessToken'], 'both halves in one statement');
    }

    public function testAnInvalidGrantEndsTheConnectionInsteadOfBeingRetried(): void
    {
        // Spent, expired, revoked in the dashboard or reuse already detected - fastmon
        // does not say which, and none of them can be retried. Keeping the token would
        // leave the panel retrying a connection that is already gone, and every retry
        // looks like theft from the other side.
        $this->connected('fmt_old', expiresIn: 0);

        $provider = $this->provider([
            $this->discovery(),
            new MockResponse(json_encode([
                'error' => 'invalid_grant',
                'error_description' => 'Invalid or expired refresh token.',
            ], \JSON_THROW_ON_ERROR), ['http_code' => 400]),
        ]);

        $this->expectException(FastmonCredentialExpiredException::class);

        try {
            $provider->token();
        } finally {
            self::assertNull($this->row['refreshToken'] ?? null);
            self::assertNull($this->row['accessToken'] ?? null);
            // The application stays linked: reconnecting for the same organization must
            // not cost the merchant the storefront snippets.
            self::assertSame('src123', $this->config[ConfigResolver::DOMAIN . 'sourceHash']);
        }
    }

    public function testARefreshAnotherProcessAlreadyDidIsNotDoneAgain(): void
    {
        // The panel opens several admin API calls at once. Two of them finding the same
        // token rejected would send the same refresh token twice and disconnect a shop
        // that did nothing wrong. Whoever loses the lock re-reads what the winner stored
        // instead of spending its own copy.
        //
        // No HTTP response is queued on purpose: a refresh going out here would fail the
        // test rather than quietly pass it.
        $this->connected('fmt_stale', expiresIn: 600);

        $provider = $this->provider([]);
        $seen = [];

        $result = $provider->call(function (string $token) use (&$seen): string {
            $seen[] = $token;

            if (\count($seen) === 1) {
                // What the winner leaves behind while this call is in flight.
                $this->row['accessToken'] = 'fmt_from_the_winner';
                $this->row['accessTokenExpiresAt'] = new DateTimeImmutable('@' . (time() + 900));

                throw new FastmonUnauthorizedException('rejected');
            }

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(['fmt_stale', 'fmt_from_the_winner'], $seen);
        self::assertSame('fmr_old', $this->row['refreshToken']);
    }

    public function testTheWaiterReadsWhatTheWinnerStored(): void
    {
        // The failure this guards against, seen in production: two admin API calls, one
        // lock, and a store that answered from a snapshot taken before the winner wrote.
        // The waiter found the refresh token it was about to present, presented it, and
        // fastmon read that as two parties holding one token and ended the connection.
        // The row is re-read inside the lock, and nothing memoises it in front.
        //
        // No HTTP response is queued on purpose: a refresh going out here is the bug.
        $this->connected('fmt_stale', expiresIn: 0);

        $provider = $this->provider([]);

        // What the winner wrote in its own process while this one waited for the lock.
        $this->row['accessToken'] = 'fmt_from_the_winner';
        $this->row['accessTokenExpiresAt'] = new DateTimeImmutable('@' . (time() + 900));
        $this->row['refreshToken'] = 'fmr_successor';

        self::assertSame('fmt_from_the_winner', $provider->token());
        self::assertSame('fmr_successor', $this->row['refreshToken']);
    }

    public function testARejectedTokenIsRefreshedAndTheCallRetriedOnce(): void
    {
        // An access token can expire between two calls of the same panel load, and a
        // clock a few minutes off makes the proactive refresh miss. Neither is something
        // a merchant should have to read an error about.
        $this->connected('fmt_live', expiresIn: 600);

        $provider = $this->provider([$this->discovery(), $this->tokenResponse('fmt_new', 'fmr_new')]);
        $seen = [];

        $result = $provider->call(function (string $token) use (&$seen): string {
            $seen[] = $token;

            if (\count($seen) === 1) {
                throw new FastmonUnauthorizedException('rejected');
            }

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(['fmt_live', 'fmt_new'], $seen);
    }

    public function testARetryHappensExactlyOnce(): void
    {
        $this->connected('fmt_live', expiresIn: 600);

        $provider = $this->provider([$this->discovery(), $this->tokenResponse('fmt_new', 'fmr_new')]);
        $seen = [];

        $this->expectException(FastmonUnauthorizedException::class);

        try {
            $provider->call(function (string $token) use (&$seen): string {
                $seen[] = $token;

                throw new FastmonUnauthorizedException('rejected');
            });
        } finally {
            self::assertSame(['fmt_live', 'fmt_new'], $seen);
        }
    }

    public function testAPastedKeyIsPassedThroughAndNeverRefreshed(): void
    {
        // It does not expire and there is nothing to rotate, which is exactly why that
        // fallback still exists. A retry would only be a second failure.
        $this->row = ['manualToken' => 'fmo_key'];

        $provider = $this->provider([]);
        $seen = [];

        self::assertSame('fmo_key', $provider->token());

        $this->expectException(FastmonUnauthorizedException::class);

        try {
            $provider->call(function (string $token) use (&$seen): string {
                $seen[] = $token;

                throw new FastmonUnauthorizedException('rejected');
            });
        } finally {
            self::assertSame(['fmo_key'], $seen);
        }
    }

    public function testAShopThatNeverConnectedSaysSo(): void
    {
        $this->expectException(FastmonUnauthorizedException::class);
        $this->provider([])->token();
    }

    private function connected(string $accessToken, int $expiresIn): void
    {
        $this->row = [
            'clientId' => 'dyn_1',
            'redirectUri' => 'https://shop.example.com/admin',
            'refreshToken' => 'fmr_old',
            'scopes' => 'org:read app:read app:write site:read',
            'accessTokenExpiresAt' => new DateTimeImmutable('@' . (time() + $expiresIn)),
            'accessToken' => $accessToken,
        ];
        $this->config[ConfigResolver::DOMAIN . 'sourceHash'] = 'src123';
    }

    private function discovery(): MockResponse
    {
        return new MockResponse(json_encode([
            'issuer' => self::BASE,
            'authorization_endpoint' => self::BASE . '/auth/app/authorize',
            'token_endpoint' => self::BASE . '/auth/app/token',
            'registration_endpoint' => self::BASE . '/auth/app/register',
            'revocation_endpoint' => self::BASE . '/auth/app/revoke',
        ], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }

    private function tokenResponse(string $access, string $refresh): MockResponse
    {
        return new MockResponse(json_encode([
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_in' => 900,
            'scope' => 'org:read app:read app:write site:read',
            'organization' => ['id' => 'org-7', 'name' => 'Acme'],
        ], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function provider(array $responses): AccessTokenProvider
    {
        $systemConfig = $this->systemConfig();

        return new AccessTokenProvider(
            new FastmonOAuthClient(new MockHttpClient($responses)),
            new ConnectionStore($this->connectionRepository(), $systemConfig),
            new ConfigResolver($systemConfig),
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
        );
    }
}
