<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\Api\FastmonClient;
use Fastmon\Collector\Api\FastmonOAuthClient;
use Fastmon\Collector\Connection\AccessTokenProvider;
use Fastmon\Collector\Connection\ConnectionStore;
use Fastmon\Collector\FastmonCollectorException;
use Fastmon\Collector\Provisioning\ApplicationProvisioner;
use Fastmon\Collector\Service\ConfigResolver;
use Fastmon\Collector\Tests\Unit\Fake\StoresConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class ApplicationProvisionerTest extends TestCase
{
    use StoresConnection;

    /** @var list<MockResponse> */
    private array $sent = [];

    protected function setUp(): void
    {
        // A pasted key: it passes straight through the token provider, so what these
        // tests exercise is the provisioner and nothing about refreshing.
        $this->row = ['manualToken' => 'fmk_live'];
    }

    public function testCreatingFillsTheDefaultsAndPointsTheStorefrontAtTheResult(): void
    {
        $provisioner = $this->provisioner([
            $this->application('app-1', 'Shopware', 'src1', 'pix1', 201),
            $this->organizations([['id' => 'org-7', 'name' => 'Acme']]),
        ]);

        $application = $provisioner->create('org-7', '', '', '');

        self::assertSame('app-1', $application['id']);

        // What fastmon was asked for: the shop's name, production, the PII-free preset,
        // and the two settings that make one application cover every sales channel.
        $body = $this->json($this->sent[0]);
        self::assertStringEndsWith('/v1/organizations/org-7/applications', $this->sent[0]->getRequestUrl());
        self::assertSame(ApplicationProvisioner::DEFAULT_NAME, $body['name']);
        self::assertSame('prod', $body['environment']);
        self::assertSame(ApplicationProvisioner::DEFAULT_PRESET, $body['preset']);
        self::assertSame('auto', $body['site_policy']);
        self::assertSame('shopware6', $body['pagetype_ruleset']);

        // And what the storefront renders from here on.
        self::assertSame('src1', $this->config[ConfigResolver::DOMAIN . 'trackerId']);
        self::assertSame('pix1', $this->config[ConfigResolver::DOMAIN . 'pixelId']);
        self::assertSame('app-1', $this->row['applicationId']);
        self::assertSame('org-7', $this->row['organizationId']);
        // Resolved from fastmon, not taken from the browser.
        self::assertSame('Acme', $this->row['organizationName']);
    }

    public function testTheMerchantsChoicesAreSentAsGiven(): void
    {
        $provisioner = $this->provisioner([
            $this->application('app-2', 'Staging shop', 'src2', 'pix2', 201),
            $this->organizations([['id' => 'org-7', 'name' => 'Acme']]),
        ]);

        $provisioner->create('org-7', 'Staging shop', 'staging', 'strict');

        $body = $this->json($this->sent[0]);
        self::assertSame('Staging shop', $body['name']);
        self::assertSame('staging', $body['environment']);
        self::assertSame('strict', $body['preset']);
    }

    public function testAttachingReReadsTheHashesRatherThanTrustingTheBrowser(): void
    {
        // A stale list in an open admin tab must not be able to write a tracker id that
        // no longer exists, so the application is fetched and its hashes are what is
        // stored.
        $provisioner = $this->provisioner([
            $this->application('app-9', 'Shop', 'fresh', 'pixfresh', 200),
            $this->organizations([['id' => 'org-7', 'name' => 'Acme']]),
        ]);

        $application = $provisioner->attach('org-7', 'app-9');

        self::assertStringEndsWith('/v1/applications/app-9', $this->sent[0]->getRequestUrl());
        self::assertSame('fresh', $application['trackerId']);
        self::assertSame('fresh', $this->config[ConfigResolver::DOMAIN . 'trackerId']);
        self::assertSame('pixfresh', $this->config[ConfigResolver::DOMAIN . 'pixelId']);
        self::assertSame('app-9', $this->row['applicationId']);
    }

    public function testAnApplicationWithoutATrackerIdIsRefusedBeforeAnythingIsWritten(): void
    {
        // The storefront cannot emit for it, and a half-linked shop would render nothing
        // while the panel reports a link.
        $provisioner = $this->provisioner([
            $this->application('app-9', 'Shop', '', '', 200),
        ]);

        try {
            $provisioner->attach('org-7', 'app-9');
            self::fail('an application without a tracker id must be refused');
        } catch (FastmonCollectorException) {
            self::assertNull($this->row['applicationId'] ?? null);
            self::assertArrayNotHasKey(ConfigResolver::DOMAIN . 'trackerId', $this->config);
        }
    }

    public function testAnUnresolvableOrganizationNameDoesNotCostTheLink(): void
    {
        // The name is a label. Failing the provisioning over it would leave the merchant
        // without a link because a list call hiccuped.
        $provisioner = $this->provisioner([
            $this->application('app-1', 'Shopware', 'src1', 'pix1', 201),
            new MockResponse(json_encode(['code' => 'internal', 'message' => 'boom'], \JSON_THROW_ON_ERROR), ['http_code' => 500]),
        ]);

        $provisioner->create('org-7', '', '', '');

        self::assertSame('src1', $this->config[ConfigResolver::DOMAIN . 'trackerId']);
        self::assertSame('', $this->row['organizationName']);
    }

    public function testTheTrackerIdIsWrittenLast(): void
    {
        // A half-written link renders nothing rather than a script tag with an empty id,
        // which only holds if the value that turns the snippets on is the last one in.
        $order = [];
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(fn (string $key): mixed => $this->config[$key] ?? null);
        $systemConfig->method('set')->willReturnCallback(static function (string $key) use (&$order): void {
            $order[] = str_replace(ConfigResolver::DOMAIN, '', $key);
        });

        $store = new ConnectionStore($this->connectionRepository(), $systemConfig);
        $config = new ConfigResolver($systemConfig);
        $provisioner = new ApplicationProvisioner(
            new FastmonClient(new MockHttpClient([
                $this->application('app-1', 'Shopware', 'src1', 'pix1', 201),
                $this->organizations([]),
            ])),
            $this->tokens($store, $config),
            $store,
            $config,
            new NullLogger(),
        );

        $provisioner->create('org-7', '', '', '');

        self::assertSame(['trackerId'], \array_slice($order, -1));
    }

    public function testSitesAreNotAskedForWithoutAnApplication(): void
    {
        // An empty MockHttpClient throws on the first request, so this also proves that
        // no call was made.
        self::assertSame([], $this->provisioner([])->sites());
    }

    public function testSitesAreTheDomainsFastmonHasSeen(): void
    {
        $this->row['applicationId'] = 'app-1';

        $sites = $this->provisioner([new MockResponse(json_encode(['data' => [
            ['id' => 's1', 'domain' => 'shop.example', 'name' => 'Shop'],
        ]], \JSON_THROW_ON_ERROR), ['http_code' => 200])])->sites();

        self::assertStringEndsWith('/v1/applications/app-1/sites', $this->sent[0]->getRequestUrl());
        self::assertSame([['id' => 's1', 'domain' => 'shop.example', 'name' => 'Shop']], $sites);
    }

    private function application(string $id, string $name, string $trackerId, string $pixelId, int $status): MockResponse
    {
        $data = ['id' => $id, 'name' => $name, 'environment' => 'prod'];

        if ($trackerId !== '') {
            $data['source_hash'] = $trackerId;
            $data['collector_hash'] = $pixelId;
        }

        return new MockResponse(json_encode($data, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }

    /**
     * @param list<array{id: string, name: string}> $organizations
     */
    private function organizations(array $organizations): MockResponse
    {
        return new MockResponse(json_encode(['data' => $organizations], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }

    /**
     * @return array<mixed>
     */
    private function json(MockResponse $response): array
    {
        $body = $response->getRequestOptions()['body'] ?? '';
        $decoded = json_decode(\is_string($body) ? $body : '', true);

        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param list<MockResponse> $api
     */
    private function provisioner(array $api): ApplicationProvisioner
    {
        $this->sent = $api;
        $systemConfig = $this->systemConfig();
        $store = new ConnectionStore($this->connectionRepository(), $systemConfig);
        $config = new ConfigResolver($systemConfig);

        return new ApplicationProvisioner(
            new FastmonClient(new MockHttpClient($api)),
            $this->tokens($store, $config),
            $store,
            $config,
            new NullLogger(),
        );
    }

    private function tokens(ConnectionStore $store, ConfigResolver $config): AccessTokenProvider
    {
        return new AccessTokenProvider(
            new FastmonOAuthClient(new MockHttpClient([])),
            $store,
            $config,
            new LockFactory(new InMemoryStore()),
            new NullLogger()
        );
    }
}
