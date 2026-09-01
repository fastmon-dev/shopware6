<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\Api\FastmonCredentialExpiredException;
use Fastmon\Collector\Api\FastmonOAuthClient;
use Fastmon\Collector\Api\FastmonUnauthorizedException;
use Fastmon\Collector\Connection\AccessTokenProvider;
use Fastmon\Collector\Connection\ConnectionStore;
use Fastmon\Collector\Service\ConfigResolver;
use Fastmon\Collector\Tests\Unit\Fake\StoresSystemConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

class AccessTokenProviderTest extends TestCase
{
    use StoresSystemConfig;

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
        self::assertSame('fmr_new', $this->stored[ConfigResolver::DOMAIN . 'oauthRefreshToken']);
        self::assertSame('fmt_new', $this->stored[ConfigResolver::DOMAIN . 'oauthAccessToken']);
    }

    public function testARotationIsWrittenWithoutDroppingThePageCache(): void
    {
        // A rotated token changes nothing a visitor can see, and it happens whenever
        // somebody works in the administration while the stored access token has aged
        // out. Written loudly, every rotation would rebuild the shop's full page cache;
        // written silently, it costs nobody anything. See `ConnectionStore::set()`.
        $this->connected('fmt_old', expiresIn: 30);

        $this->provider([$this->discovery(), $this->tokenResponse('fmt_new', 'fmr_new')])->token();

        foreach (['oauthRefreshToken', 'oauthScopes', 'oauthExpiresAt', 'oauthAccessToken'] as $key) {
            self::assertTrue($this->silent[ConfigResolver::DOMAIN . $key], $key . ' must be written silently');
        }
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

    public function testTheSuccessorIsWrittenBeforeTheAccessTokenItComesWith(): void
    {
        // The order is the whole point: a process that died between the two writes must
        // lose an access token, never the refresh token. Presenting a spent one is what
        // fastmon reads as theft, and it ends the connection.
        $this->connected('fmt_old', expiresIn: 0);

        $this->provider([$this->discovery(), $this->tokenResponse('fmt_new', 'fmr_new')])->token();

        $written = array_keys($this->stored);

        self::assertLessThan(
            array_search(ConfigResolver::DOMAIN . 'oauthAccessToken', $written, true),
            array_search(ConfigResolver::DOMAIN . 'oauthRefreshToken', $written, true)
        );
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
            self::assertArrayNotHasKey(ConfigResolver::DOMAIN . 'oauthRefreshToken', $this->stored);
            self::assertArrayNotHasKey(ConfigResolver::DOMAIN . 'oauthAccessToken', $this->stored);
            // The application stays linked: reconnecting for the same organization must
            // not cost the merchant the storefront snippets.
            self::assertSame('src123', $this->stored[ConfigResolver::DOMAIN . 'trackerId']);
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
                $this->stored[ConfigResolver::DOMAIN . 'oauthAccessToken'] = 'fmt_from_the_winner';
                $this->stored[ConfigResolver::DOMAIN . 'oauthExpiresAt'] = (string) (time() + 900);

                throw new FastmonUnauthorizedException('rejected');
            }

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(['fmt_stale', 'fmt_from_the_winner'], $seen);
        self::assertSame('fmr_old', $this->stored[ConfigResolver::DOMAIN . 'oauthRefreshToken']);
    }

    public function testTheWaiterReadsPastItsOwnRequestSnapshot(): void
    {
        // The failure this guards against, seen in production: two admin API calls, one
        // lock, and a SystemConfigService that memoises the whole configuration per
        // request. The waiter re-read its own snapshot, found the refresh token it was
        // about to present, and presented it - which fastmon reads as two parties holding
        // one token, so it ended the connection.
        //
        // No HTTP response is queued on purpose: a refresh going out here is the bug.
        $this->connected('fmt_stale', expiresIn: 0);

        $provider = $this->provider([], memoized: true);

        // What the winner wrote in its own process while this one waited for the lock.
        $this->stored[ConfigResolver::DOMAIN . 'oauthAccessToken'] = 'fmt_from_the_winner';
        $this->stored[ConfigResolver::DOMAIN . 'oauthExpiresAt'] = (string) (time() + 900);
        $this->stored[ConfigResolver::DOMAIN . 'oauthRefreshToken'] = 'fmr_successor';

        self::assertSame('fmt_from_the_winner', $provider->token());
        self::assertSame('fmr_successor', $this->stored[ConfigResolver::DOMAIN . 'oauthRefreshToken']);
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
        $this->stored = [ConfigResolver::DOMAIN . 'apiToken' => 'fmo_key'];

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
        $this->stored = [
            ConfigResolver::DOMAIN . 'oauthClientId' => 'dyn_1',
            ConfigResolver::DOMAIN . 'oauthRedirectUri' => 'https://shop.example.com/admin',
            ConfigResolver::DOMAIN . 'oauthRefreshToken' => 'fmr_old',
            ConfigResolver::DOMAIN . 'oauthScopes' => 'org:read app:read app:write site:read',
            ConfigResolver::DOMAIN . 'oauthExpiresAt' => (string) (time() + $expiresIn),
            ConfigResolver::DOMAIN . 'oauthAccessToken' => $accessToken,
            ConfigResolver::DOMAIN . 'trackerId' => 'src123',
        ];
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
    private function provider(array $responses, bool $memoized = false): AccessTokenProvider
    {
        $systemConfig = $this->systemConfig($memoized);

        return new AccessTokenProvider(
            new FastmonOAuthClient(new MockHttpClient($responses)),
            new ConnectionStore($systemConfig, $this->database()),
            new ConfigResolver($systemConfig),
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
        );
    }
}
