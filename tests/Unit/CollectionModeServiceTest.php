<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\Api\FastmonClient;
use Fastmon\Collector\Collection\CollectionMode;
use Fastmon\Collector\Collection\CollectionModeService;
use Fastmon\Collector\Collection\CollectionNotReadyException;
use Fastmon\Collector\Collection\DomainCheckResult;
use Fastmon\Collector\Collection\EndpointChecker;
use Fastmon\Collector\Connection\ConnectionService;
use Fastmon\Collector\Connection\ConnectionStore;
use Fastmon\Collector\Connection\DeviceAuthorizationSession;
use Fastmon\Collector\Service\ConfigResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class CollectionModeServiceTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $stored = [];

    /** @var list<string> */
    private array $calls = [];

    /** @var array<string, mixed> */
    private array $sentBody = [];

    public function testAModeIsRefusedWhenAnOriginIsNotSetUp(): void
    {
        // The guarantee this class exists for. The collector endpoint is baked into the
        // bundle fastmon serves, so applying a mode before the proxy exists makes every
        // tracker in every browser post into a 404 - no error, no data, and nobody
        // notices until someone opens the dashboard days later.
        $service = $this->service([
            new DomainCheckResult('https://shop.example', true, true),
            new DomainCheckResult('https://shop.at', false, false, DomainCheckResult::REASON_COLLECTOR_STATUS, '404'),
        ]);

        try {
            $service->apply(CollectionMode::RELATIVE);
            self::fail('expected the mode change to be refused');
        } catch (CollectionNotReadyException $e) {
            // The results travel with the refusal, so the administration can name the
            // origin and the reason in the reader's language instead of echoing English.
            $failed = array_values(array_filter($e->toArray(), static fn (array $d): bool => !$d['ready']));

            self::assertSame('https://shop.at', $failed[0]['domain']);
            self::assertSame(DomainCheckResult::REASON_COLLECTOR_STATUS, $failed[0]['reason']);
            self::assertSame('404', $failed[0]['detail']);
        }

        self::assertSame([], $this->calls, 'fastmon must not be touched when the check failed');
        self::assertNull($this->storedValue('collectionMode'));
    }

    public function testAModeIsRefusedWhenNothingCouldBeChecked(): void
    {
        // No origin resolved means no evidence, and no evidence is not proof.
        $service = $this->service([]);

        $this->expectException(CollectionNotReadyException::class);
        $service->apply(CollectionMode::RELATIVE);
    }

    public function testRelativeIsAppliedOnlyAfterEveryOriginPassed(): void
    {
        $service = $this->service([
            new DomainCheckResult('https://shop.example', true, true),
            new DomainCheckResult('https://shop.at', true, true),
        ]);

        $service->apply(CollectionMode::RELATIVE);

        self::assertSame(['PATCH /v1/applications/app-1'], $this->calls);
        self::assertSame('relative', $this->sentBody['collector_mode'] ?? null);
        // fastmon rejects a relative mode carrying an endpoint rather than ignoring it,
        // so the key has to be sent and it has to be null.
        self::assertArrayHasKey('collector_endpoint', $this->sentBody);
        self::assertNull($this->sentBody['collector_endpoint']);
        self::assertSame('relative', $this->storedValue('collectionMode'));
    }

    public function testCustomCarriesTheNormalisedEndpoint(): void
    {
        $service = $this->service([new DomainCheckResult('https://metrics.example.com', true, true)]);

        $service->apply(CollectionMode::CUSTOM, 'metrics.example.com/');

        self::assertSame('custom', $this->sentBody['collector_mode'] ?? null);
        self::assertSame('https://metrics.example.com', $this->sentBody['collector_endpoint'] ?? null);
        self::assertSame('https://metrics.example.com', $this->storedValue('customCollectorDomain'));
    }

    public function testCustomWithoutADomainIsRefusedBeforeAnythingIsProbed(): void
    {
        $service = $this->service([new DomainCheckResult('https://shop.example', true, true)]);

        $this->expectExceptionMessage('Enter the domain');
        $service->apply(CollectionMode::CUSTOM, '   ');
    }

    public function testTheFastmonDefaultNeedsNoProof(): void
    {
        // Going back always works, so a merchant whose proxy just broke must not have to
        // pass a check to undo it.
        $service = $this->service([
            new DomainCheckResult('https://shop.example', false, false, DomainCheckResult::REASON_SCRIPT_STATUS, '404'),
        ]);

        $service->apply(CollectionMode::FASTMON);

        self::assertSame(['PATCH /v1/applications/app-1'], $this->calls);
        self::assertSame('default', $this->storedValue('collectionMode'));
    }

    public function testTheStatusProbesOnlyTheModeItWasAskedAbout(): void
    {
        // A probe reaches out to real origins, and a result taken against one mode says
        // nothing about another.
        $service = $this->service([new DomainCheckResult('https://shop.example', true, true)]);

        $idle = $service->describe();
        self::assertFalse($idle['checked']);
        self::assertSame('', $idle['checkedMode']);

        $probed = $service->describe(CollectionMode::RELATIVE);
        self::assertTrue($probed['checked']);
        self::assertSame('relative', $probed['checkedMode']);
        self::assertTrue($probed['ready']);
    }

    private function storedValue(string $key): mixed
    {
        return $this->stored[ConfigResolver::DOMAIN . $key] ?? null;
    }

    /**
     * @param list<DomainCheckResult> $results
     */
    private function service(array $results): CollectionModeService
    {
        $this->stored += [
            ConfigResolver::DOMAIN . 'apiToken' => 'fm_token',
            ConfigResolver::DOMAIN . 'applicationId' => 'app-1',
            ConfigResolver::DOMAIN . 'trackerId' => 'srchash',
            ConfigResolver::DOMAIN . 'pixelId' => 'colhash',
        ];

        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(fn (string $k): mixed => $this->stored[$k] ?? null);
        $systemConfig->method('set')->willReturnCallback(function (string $k, mixed $v): void {
            $this->stored[$k] = $v;
        });

        $client = new FastmonClient(new MockHttpClient(
            function (string $method, string $url, array $options): MockResponse {
                $this->calls[] = $method . ' ' . parse_url($url, \PHP_URL_PATH);
                $decoded = json_decode((string) ($options['body'] ?? '{}'), true);
                $this->sentBody = \is_array($decoded) ? $decoded : [];

                return new MockResponse(json_encode([
                    'id' => 'app-1', 'name' => 'Shopware', 'source_hash' => 'srchash',
                    'collector_hash' => 'colhash', 'environment' => 'prod', 'site_count' => 1,
                ], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
            }
        ));

        $store = new ConnectionStore($systemConfig);
        $config = new ConfigResolver($systemConfig);

        $checker = $this->createMock(EndpointChecker::class);
        $checker->method('check')->willReturn($results);
        $checker->method('storefrontOrigins')->willReturn(['https://shop.example']);
        $checker->method('normaliseOrigin')->willReturnCallback(
            static fn (string $v): string => trim($v) === '' ? '' : 'https://' . rtrim(preg_replace('#^https?://#', '', trim($v)) ?? '', '/')
        );

        return new CollectionModeService(
            $client,
            new ConnectionService($client, $store, new DeviceAuthorizationSession($systemConfig), $config, new NullLogger()),
            $store,
            $config,
            $checker,
            $systemConfig,
            new NullLogger(),
        );
    }
}
