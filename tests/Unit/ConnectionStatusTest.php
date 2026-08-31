<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\Api\FastmonClient;
use Fastmon\Collector\Api\FastmonOAuthClient;
use Fastmon\Collector\Connection\AccessTokenProvider;
use Fastmon\Collector\Connection\ConnectionStatus;
use Fastmon\Collector\Connection\ConnectionStore;
use Fastmon\Collector\Service\ConfigResolver;
use Fastmon\Collector\Storefront\StorefrontCache;
use Fastmon\Collector\Tests\Unit\Fake\StoresSystemConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * What the panel is told, which is a different question from how the shop connects.
 */
class ConnectionStatusTest extends TestCase
{
    use StoresSystemConfig;

    private const BASE = 'https://api.fastmon.eu';
    private const ADMIN = 'https://shop.example.com/admin';

    public function testTheStatusNeverReturnsTheCredential(): void
    {
        $this->connected();

        $status = $this->reporter()->describe();

        self::assertTrue($status['connected']);
        self::assertTrue($status['provisioned']);
        self::assertSame('app', $status['connectionKind']);
        self::assertContains('app:write', $status['scopes']);
        self::assertSame([], $status['missingScopes']);
        self::assertNotContains('fmt_live', $status, 'a stored credential must not be readable through the admin API');
        self::assertNotContains('fmr_live', $status);
    }

    public function testThePanelIsGivenAWayIntoFastmonItself(): void
    {
        // Assembled on this side, so the dashboard's routes are written down once. The
        // panel deliberately shows no measurements, so the link is how a merchant gets
        // to them.
        $this->connected();

        $status = $this->reporter()->describe();

        self::assertSame('https://app.fastmon.eu/org/org-7/dashboard', $status['dashboardUrl']);
        self::assertSame('https://app.fastmon.eu/org/org-7/applications', $status['applicationsUrl']);
    }

    public function testWithoutAnOrganizationThereIsNoLinkToOffer(): void
    {
        // Both pages live under an organization. A link to nothing is worse than none.
        $this->stored = [ConfigResolver::DOMAIN . 'apiToken' => 'fmo_key'];

        $status = $this->reporter()->describe();

        self::assertSame('', $status['dashboardUrl']);
        self::assertSame('', $status['applicationsUrl']);
    }

    public function testAScopeTheApproverDidNotTickIsNamedBeforeItFails(): void
    {
        // The approver may grant less than was asked for, and their role cuts the list
        // again. Without this the merchant meets it as an API error on whichever button
        // needed the permission.
        $this->connected();
        $this->stored[ConfigResolver::DOMAIN . 'oauthScopes'] = 'org:read app:write site:read';

        self::assertSame(['app:read'], $this->reporter()->describe()['missingScopes']);
    }

    public function testAPastedKeyIsNotReportedAsMissingEverything(): void
    {
        // A key carries its permissions on fastmon's side and never tells the shop what
        // they are, so an empty scope list is "unknown", not "none".
        $this->stored = [ConfigResolver::DOMAIN . 'apiToken' => 'fmo_key'];

        $status = $this->reporter()->describe();

        self::assertSame('token', $status['connectionKind']);
        self::assertSame([], $status['missingScopes']);
    }

    public function testARejectedCredentialIsReportedAsSuchRatherThanAsDisconnected(): void
    {
        $this->connected();

        $status = $this->reporter(api: [new MockResponse('', ['http_code' => 401])], oauth: [
            // The retry after a 401: the token is refreshed once before giving up.
            $this->discovery(),
            new MockResponse(json_encode(['error' => 'invalid_grant'], \JSON_THROW_ON_ERROR), ['http_code' => 400]),
        ])->describe(verify: true);

        self::assertTrue($status['connected']);
        self::assertFalse($status['tokenValid']);
        self::assertNotSame('', $status['error']);
    }

    public function testATransientFailureDoesNotInvalidateAWorkingConnection(): void
    {
        // Telling a merchant to reconnect because fastmon was briefly unreachable would
        // cost them a connection that is fine.
        $this->connected();

        $status = $this->reporter(api: [new MockResponse('', ['http_code' => 503])])->describe(verify: true);

        self::assertTrue($status['connected']);
        self::assertNull($status['tokenValid']);
        self::assertNotSame('', $status['error']);
    }

    public function testAConnectionThatNoLongerReachesTheOrganizationIsNotReportedAsHealthy(): void
    {
        // It keeps working for everything except this shop's data, which would look like
        // a healthy connection collecting nothing.
        $this->connected();

        $status = $this->reporter(api: [
            $this->organizations([['id' => 'org-other', 'name' => 'Somebody else']]),
        ])->describe(verify: true);

        self::assertFalse($status['tokenValid']);
        self::assertStringContainsString('no longer reaches', $status['error']);
    }

    public function testAnApplicationThatIsNotThereIsReported(): void
    {
        // A working credential says nothing about the application still existing. It can
        // be deleted in the dashboard, or refer to an id from a different fastmon
        // instance - and the storefront then keeps serving a snippet that collects
        // nothing.
        $this->connected(applicationId: 'app-gone');

        $status = $this->reporter(api: [
            $this->organizations([['id' => 'org-7', 'name' => 'Acme']]),
            new MockResponse(json_encode(
                ['error' => ['code' => 'resource_not_found', 'message' => 'No such application']],
                \JSON_THROW_ON_ERROR
            ), ['http_code' => 404]),
        ])->describe(verify: true);

        self::assertTrue($status['tokenValid']);
        self::assertFalse($status['applicationValid']);
        self::assertStringContainsString('could not be found', $status['error']);
    }

    public function testAChangedTrackerIdTellsThePanelTheStorefrontIsStale(): void
    {
        // Nothing invalidates a cached page here. The panel reports it and the merchant
        // decides when to pay for the rebuild.
        $this->connected(applicationId: 'app-1');

        $status = $this->reporter(api: [
            $this->organizations([['id' => 'org-7', 'name' => 'Acme']]),
            new MockResponse(json_encode([
                'id' => 'app-1', 'name' => 'Shopware',
                'source_hash' => 'newhash', 'collector_hash' => 'newpixel',
            ], \JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ])->describe(verify: true);

        self::assertTrue($status['cacheStale']);
    }

    public function testReadingTheSameHashesBackDoesNotCryStale(): void
    {
        // The panel re-reads the application every time it opens. A warning that fires
        // when nothing moved is a warning nobody reads.
        $this->connected(applicationId: 'app-1');

        $status = $this->reporter(api: [
            $this->organizations([['id' => 'org-7', 'name' => 'Acme']]),
            new MockResponse(json_encode([
                'id' => 'app-1', 'name' => 'Shopware',
                'source_hash' => 'src123', 'collector_hash' => '',
            ], \JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ])->describe(verify: true);

        self::assertFalse($status['cacheStale']);
    }

    public function testARotatedTrackerIdIsAdoptedRatherThanReported(): void
    {
        // Rotating in the dashboard invalidates the embed everywhere it is deployed, so a
        // shop still serving the old id collects nothing while looking perfectly fine.
        // fastmon owns the hashes: the shop takes what it is told, instead of asking the
        // merchant to resolve a disagreement they did not cause.
        $this->connected(applicationId: 'app-1');

        $status = $this->reporter(api: [
            $this->organizations([['id' => 'org-7', 'name' => 'Acme']]),
            new MockResponse(json_encode([
                'id' => 'app-1', 'name' => 'Shopware',
                'source_hash' => 'newhash', 'collector_hash' => 'newpixel',
            ], \JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ])->describe(verify: true);

        self::assertTrue($status['applicationValid']);
        self::assertSame('', $status['error']);

        // And the storefront is emitting the new ones from here on. Written silently,
        // like everything else: what the cached pages still carry is reported to the
        // panel rather than thrown away behind the merchant's back.
        self::assertSame('newhash', $this->stored[ConfigResolver::DOMAIN . 'trackerId']);
        self::assertTrue($this->silent[ConfigResolver::DOMAIN . 'trackerId']);
        self::assertTrue($status['cacheStale']);
        self::assertSame('newpixel', $this->stored[ConfigResolver::DOMAIN . 'pixelId']);
        self::assertSame('newhash', $status['trackerId']);
    }

    private function connected(string $applicationId = ''): void
    {
        $this->stored = [
            ConfigResolver::DOMAIN . 'oauthClientId' => 'dyn_1',
            ConfigResolver::DOMAIN . 'oauthRedirectUri' => self::ADMIN,
            ConfigResolver::DOMAIN . 'oauthRefreshToken' => 'fmr_live',
            ConfigResolver::DOMAIN . 'oauthScopes' => 'org:read app:read app:write site:read',
            ConfigResolver::DOMAIN . 'oauthExpiresAt' => (string) (time() + 600),
            ConfigResolver::DOMAIN . 'oauthAccessToken' => 'fmt_live',
            ConfigResolver::DOMAIN . 'accountEmail' => 'merchant@example.com',
            ConfigResolver::DOMAIN . 'organizationId' => 'org-7',
            ConfigResolver::DOMAIN . 'organizationName' => 'Acme',
            ConfigResolver::DOMAIN . 'applicationId' => $applicationId,
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

    /**
     * @param list<array{id: string, name: string}> $organizations
     */
    private function organizations(array $organizations): MockResponse
    {
        return new MockResponse(
            json_encode(['data' => $organizations], \JSON_THROW_ON_ERROR),
            ['http_code' => 200]
        );
    }

    /**
     * @param list<MockResponse> $api
     * @param list<MockResponse> $oauth
     */
    private function reporter(array $api = [], array $oauth = []): ConnectionStatus
    {
        $systemConfig = $this->systemConfig();
        $store = new ConnectionStore($systemConfig);
        $config = new ConfigResolver($systemConfig);
        $oauthClient = new FastmonOAuthClient(new MockHttpClient($oauth));

        return new ConnectionStatus(
            new FastmonClient(new MockHttpClient($api)),
            $store,
            new AccessTokenProvider($oauthClient, $store, $config, new LockFactory(new InMemoryStore()), new NullLogger()),
            new StorefrontCache($this->createMock(CacheInvalidator::class), $store, new NullLogger()),
            $config,
            new NullLogger(),
        );
    }
}
