<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\Api\FastmonClient;
use Fastmon\Collector\Connection\ConnectionService;
use Fastmon\Collector\Connection\ConnectionStore;
use Fastmon\Collector\Connection\DeviceAuthorizationSession;
use Fastmon\Collector\Service\ConfigResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ConnectionServiceTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $stored = [];

    public function testAnApprovedDeviceAuthorizationStoresTheToken(): void
    {
        $service = $this->service([
            // start
            new MockResponse(json_encode([
                'device_code' => 'dev-123',
                'user_code' => 'WDJB-MJHT',
                'verification_uri' => 'https://fastmon.eu/device',
                'expires_in' => 900,
                'interval' => 5,
            ], \JSON_THROW_ON_ERROR), ['http_code' => 200]),
            // poll: still waiting
            new MockResponse(json_encode(['error' => 'authorization_pending'], \JSON_THROW_ON_ERROR), ['http_code' => 400]),
            // poll: approved, with the organization the merchant consented for
            new MockResponse(json_encode([
                'access_token' => 'fm_secret',
                'account' => ['email' => 'merchant@example.com', 'name' => 'Merchant'],
                'organization' => ['id' => 'org-7', 'name' => 'Acme'],
            ], \JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);

        $started = $service->startDeviceAuthorization();

        self::assertSame('WDJB-MJHT', $started['userCode']);
        // The device code is the credential the shop redeems with, so it stays here and
        // only an opaque handle goes to the browser.
        self::assertArrayNotHasKey('deviceCode', $started);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $started['handle']);

        self::assertSame('pending', $service->pollDeviceAuthorization($started['handle'])['status']);

        $completed = $service->pollDeviceAuthorization($started['handle']);

        self::assertSame('complete', $completed['status']);
        self::assertSame('merchant@example.com', $completed['accountEmail']);
        self::assertSame('fm_secret', $this->stored[ConfigResolver::DOMAIN . 'apiToken']);

        // Settled on fastmon's consent screen, so the shop never asks again - and can
        // never end up reporting to a different organization than the one approved.
        self::assertSame('org-7', $this->stored[ConfigResolver::DOMAIN . 'organizationId']);
        self::assertSame('Acme', $this->stored[ConfigResolver::DOMAIN . 'organizationName']);
    }

    public function testAnInstanceThatDoesNotCarryTheOrganizationLeavesTheShopToAsk(): void
    {
        // Additive on both sides: against a backend that has not shipped it yet the
        // token still lands, and the module falls back to its own picker.
        $service = $this->service([
            new MockResponse(json_encode([
                'device_code' => 'dev-123',
                'user_code' => 'WDJB-MJHT',
                'verification_uri' => 'https://fastmon.eu/device',
                'expires_in' => 900,
                'interval' => 5,
            ], \JSON_THROW_ON_ERROR), ['http_code' => 200]),
            new MockResponse(json_encode([
                'access_token' => 'fm_secret',
                'account' => ['email' => 'merchant@example.com', 'name' => 'Merchant'],
            ], \JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);

        $started = $service->startDeviceAuthorization();
        $completed = $service->pollDeviceAuthorization($started['handle']);

        self::assertSame('complete', $completed['status']);
        self::assertSame('fm_secret', $this->stored[ConfigResolver::DOMAIN . 'apiToken']);
        self::assertSame('', $completed['organizationId']);
        self::assertArrayNotHasKey(ConfigResolver::DOMAIN . 'organizationId', $this->stored);
    }

    public function testAnUnknownHandleEndsTheFlowInsteadOfFailing(): void
    {
        // Expiry costs the merchant one more click, which is not worth an exception.
        $service = $this->service([]);

        self::assertSame('expired', $service->pollDeviceAuthorization('deadbeef')['status']);
    }

    public function testATokenIsVerifiedBeforeItIsStored(): void
    {
        $service = $this->service([new MockResponse('', ['http_code' => 401])]);

        $this->expectExceptionMessage('fastmon account lookup failed');

        try {
            $service->connectWithToken('fm_typo');
        } finally {
            // A typo must be reported as a typo, not stored to become a storefront that
            // quietly never provisions.
            self::assertArrayNotHasKey(ConfigResolver::DOMAIN . 'apiToken', $this->stored);
        }
    }

    public function testTheStatusNeverReturnsTheToken(): void
    {
        $this->stored = [
            ConfigResolver::DOMAIN . 'apiToken' => 'fm_secret',
            ConfigResolver::DOMAIN . 'accountEmail' => 'merchant@example.com',
            ConfigResolver::DOMAIN . 'trackerId' => 'src123',
        ];

        $status = $this->service([])->describe();

        self::assertTrue($status['connected']);
        self::assertTrue($status['provisioned']);
        self::assertNotContains('fm_secret', $status, 'a stored credential must not be readable through the admin API');
    }

    public function testAnApplicationThatIsNotThereIsReported(): void
    {
        // A working token says nothing about the application still existing. It can be
        // deleted in the dashboard, or refer to an id from a different fastmon instance -
        // and the storefront then keeps serving a snippet that collects nothing.
        $this->stored = [
            ConfigResolver::DOMAIN . 'apiToken' => 'fm_ok',
            ConfigResolver::DOMAIN . 'applicationId' => 'app-gone',
            ConfigResolver::DOMAIN . 'trackerId' => 'srchash',
        ];

        $status = $this->service([
            new MockResponse(json_encode(['email' => 'm@example.com'], \JSON_THROW_ON_ERROR), ['http_code' => 200]),
            new MockResponse(json_encode(
                ['error' => ['code' => 'resource_not_found', 'message' => 'No such application']],
                \JSON_THROW_ON_ERROR
            ), ['http_code' => 404]),
        ])->describe(verify: true);

        self::assertTrue($status['tokenValid']);
        self::assertFalse($status['applicationValid']);
        self::assertStringContainsString('could not be found', $status['error']);
    }

    public function testARotatedTrackerIdIsReported(): void
    {
        // Rotating in the dashboard invalidates the embed everywhere it is deployed. A
        // shop still serving the old id collects nothing while looking perfectly fine.
        $this->stored = [
            ConfigResolver::DOMAIN . 'apiToken' => 'fm_ok',
            ConfigResolver::DOMAIN . 'applicationId' => 'app-1',
            ConfigResolver::DOMAIN . 'trackerId' => 'oldhash',
        ];

        $status = $this->service([
            new MockResponse(json_encode(['email' => 'm@example.com'], \JSON_THROW_ON_ERROR), ['http_code' => 200]),
            new MockResponse(json_encode([
                'id' => 'app-1', 'name' => 'Shopware',
                'source_hash' => 'newhash', 'collector_hash' => 'colhash',
            ], \JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ])->describe(verify: true);

        self::assertFalse($status['applicationValid']);
        self::assertStringContainsString('tracker id changed', $status['error']);
    }

    public function testARevokedTokenIsReportedAsSuchRatherThanAsDisconnected(): void
    {
        $this->stored = [ConfigResolver::DOMAIN . 'apiToken' => 'fm_revoked'];

        $status = $this->service([new MockResponse('', ['http_code' => 401])])->describe(verify: true);

        self::assertTrue($status['connected']);
        self::assertFalse($status['tokenValid']);
        self::assertNotSame('', $status['error']);
    }

    public function testATransientFailureDoesNotInvalidateAWorkingConnection(): void
    {
        // Telling a merchant to reconnect because fastmon was briefly unreachable would
        // cost them a connection that is fine.
        $this->stored = [ConfigResolver::DOMAIN . 'apiToken' => 'fm_ok'];

        $status = $this->service([new MockResponse('', ['http_code' => 503])])->describe(verify: true);

        self::assertTrue($status['connected']);
        self::assertNull($status['tokenValid']);
        self::assertNotSame('', $status['error']);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function service(array $responses): ConnectionService
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(fn (string $key): mixed => $this->stored[$key] ?? null);
        $systemConfig->method('set')->willReturnCallback(function (string $key, mixed $value): void {
            $this->stored[$key] = $value;
        });
        $systemConfig->method('delete')->willReturnCallback(function (string $key): void {
            unset($this->stored[$key]);
        });

        return new ConnectionService(
            new FastmonClient(new MockHttpClient($responses)),
            new ConnectionStore($systemConfig),
            new DeviceAuthorizationSession($systemConfig),
            new ConfigResolver($systemConfig),
            new NullLogger(),
        );
    }
}
