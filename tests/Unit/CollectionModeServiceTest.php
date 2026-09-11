<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Doctrine\DBAL\Connection as DbalConnection;
use Fastmon\Collector\Api\FastmonClient;
use Fastmon\Collector\Api\FastmonOAuthClient;
use Fastmon\Collector\Collection\CollectionMode;
use Fastmon\Collector\Collection\CollectionModeService;
use Fastmon\Collector\Collection\CollectionNotReadyException;
use Fastmon\Collector\Collection\DomainCheckResult;
use Fastmon\Collector\Collection\EndpointChecker;
use Fastmon\Collector\Connection\AccessTokenProvider;
use Fastmon\Collector\Connection\ConnectionStore;
use Fastmon\Collector\FastmonCollectorException;
use Fastmon\Collector\Service\ConfigResolver;
use Fastmon\Collector\Tests\Unit\Fake\StoresConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

class CollectionModeServiceTest extends TestCase
{
    use StoresConnection;


    /** @var list<string> */
    private array $calls = [];

    /** @var array<mixed> */
    private array $sentBody = [];

    /** @var list<string> every probe URL the checker requested */
    private array $probed = [];

    public function testAModeIsRefusedWhenAnOriginIsNotSetUp(): void
    {
        // The guarantee this class exists for. The collector endpoint is baked into the
        // bundle fastmon serves, so applying a mode before the proxy exists makes every
        // tracker in every browser post into a 404 - no error, no data, and nobody
        // notices until someone opens the dashboard days later.
        $service = $this->service(['https://shop.example' => true, 'https://shop.at' => false]);

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
        // Both origins, both paths: a single unconfigured storefront is what has to be found.
        self::assertCount(4, $this->probed);
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
        $service = $this->service(['https://shop.example' => true, 'https://shop.at' => true]);

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
        $service = $this->service(['https://metrics.example.com' => true]);

        $service->apply(CollectionMode::CUSTOM, 'metrics.example.com/');

        self::assertSame('custom', $this->sentBody['collector_mode'] ?? null);
        self::assertSame('https://metrics.example.com', $this->sentBody['collector_endpoint'] ?? null);
        self::assertSame('https://metrics.example.com', $this->storedValue('customCollectorDomain'));
    }

    public function testAnUnlinkedShopIsRefusedBeforeAnythingIsProbed(): void
    {
        // Without an application there are no hashes to probe for. The checker would
        // answer with an empty list, and that would surface as "not ready" naming no
        // origin at all - so the clear message has to come first.
        $this->row['applicationId'] = '';
        $this->config[ConfigResolver::DOMAIN . 'sourceHash'] = '';
        $service = $this->service(['https://shop.example' => true]);

        try {
            $service->apply(CollectionMode::RELATIVE);
            self::fail('expected the unlinked shop to be refused');
        } catch (FastmonCollectorException $e) {
            self::assertSame('No fastmon application is linked to this shop.', $e->getMessage());
        }

        self::assertSame([], $this->probed);
        self::assertSame([], $this->calls);
    }

    public function testCustomWithoutADomainIsRefusedBeforeAnythingIsProbed(): void
    {
        $service = $this->service(['https://shop.example' => true]);

        $this->expectException(FastmonCollectorException::class);
        $this->expectExceptionMessage('Enter the domain');
        $service->apply(CollectionMode::CUSTOM, '   ');
    }

    public function testTheFastmonDefaultNeedsNoProof(): void
    {
        // Going back always works, so a merchant whose proxy just broke must not have to
        // pass a check to undo it.
        $service = $this->service(['https://shop.example' => false]);

        $service->apply(CollectionMode::FASTMON);

        self::assertSame([], $this->probed, 'nothing to prove, so nothing is probed');
        self::assertSame(['PATCH /v1/applications/app-1'], $this->calls);
        self::assertSame('default', $this->storedValue('collectionMode'));
    }

    public function testTheStatusProbesOnlyTheModeItWasAskedAbout(): void
    {
        // A probe reaches out to real origins, and a result taken against one mode says
        // nothing about another.
        $service = $this->service(['https://shop.example' => true]);

        $idle = $service->describe();
        self::assertFalse($idle['checked']);
        self::assertSame('', $idle['checkedMode']);

        $probed = $service->describe(CollectionMode::RELATIVE);
        self::assertTrue($probed['checked']);
        self::assertSame('relative', $probed['checkedMode']);
        self::assertTrue($probed['ready']);
    }

    public function testAModeChangedInFastmonIsAdoptedRatherThanIgnored(): void
    {
        // fastmon owns where the beacon goes: the endpoint is baked into the bundle it
        // serves, so a mode switched in the dashboard has already taken effect in every
        // browser. A panel reporting the shop's stored value would describe a setup that
        // no longer exists, and the storefront would keep loading the script from the
        // wrong host.
        $service = $this->service(['https://shop.example' => true], collectorMode: 'relative');

        self::assertSame('relative', $service->describe()['mode']);
        self::assertSame('relative', $this->storedValue('collectionMode'));
    }

    public function testACustomEndpointComesAcrossWithTheMode(): void
    {
        // The pair is one decision. A mode adopted without its endpoint would point every
        // beacon at the wrong host.
        $service = $this->service(
            ['https://shop.example' => true],
            collectorMode: 'custom',
            collectorEndpoint: 'https://metrics.example.com'
        );

        self::assertSame('custom', $service->describe()['mode']);
        self::assertSame('https://metrics.example.com', $this->storedValue('customCollectorDomain'));
    }

    public function testAModeThisReleaseDoesNotKnowLeavesTheShopAlone(): void
    {
        // Guessing would be worse than staying: what is stored is what the storefront is
        // already emitting, and it works.
        $this->config[ConfigResolver::DOMAIN . 'collectionMode'] = 'default';
        $service = $this->service(['https://shop.example' => true], collectorMode: 'something-new');

        self::assertSame('default', $service->describe()['mode']);
        self::assertSame('default', $this->storedValue('collectionMode'));
    }

    private function storedValue(string $key): mixed
    {
        return $this->config[ConfigResolver::DOMAIN . $key] ?? null;
    }

    /**
     * A shop linked to application `app-1`, whose origins answer the probe as given.
     *
     * The checker is the real one. A stand-in would let this test pass while the checker
     * and the service disagree about what "ready" means, which is precisely the seam the
     * apply guarantee runs across.
     *
     * @param array<string, bool> $origins origin => whether both probe paths answer there
     */
    private function service(array $origins, string $collectorMode = 'default', ?string $collectorEndpoint = null): CollectionModeService
    {
        $this->row += ['manualToken' => 'fm_token', 'applicationId' => 'app-1'];
        $this->config[ConfigResolver::DOMAIN . 'sourceHash'] = 'srchash';
        $this->config[ConfigResolver::DOMAIN . 'collectorHash'] = 'colhash';

        $database = $this->createMock(DbalConnection::class);
        $database->method('fetchFirstColumn')->willReturn(array_keys($origins));

        $systemConfig = $this->systemConfig();

        // One client serves both fastmon's API and the probed origins, told apart by path:
        // `/v1/…` is fastmon, `/s/…` and `/c/…` are the proxy paths on a storefront.
        $httpClient = new MockHttpClient(
            /** @param array<string, mixed> $options */
            function (string $method, string $url, array $options) use ($origins, $collectorMode, $collectorEndpoint): MockResponse {
                $path = (string) parse_url($url, \PHP_URL_PATH);

                if (str_starts_with($path, '/v1/')) {
                    $this->calls[] = $method . ' ' . $path;
                    $body = $options['body'] ?? '{}';
                    $decoded = json_decode(\is_string($body) ? $body : '{}', true);
                    $this->sentBody = \is_array($decoded) ? $decoded : [];

                    return new MockResponse(json_encode([
                        'id' => 'app-1', 'name' => 'Shopware', 'source_hash' => 'srchash',
                        'collector_hash' => 'colhash', 'environment' => 'prod', 'site_count' => 1,
                        'collector_mode' => $collectorMode, 'collector_endpoint' => $collectorEndpoint,
                    ], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
                }

                $this->probed[] = $url;

                if (str_starts_with($path, '/s/')) {
                    return new MockResponse('var e="/c/colhash";', ['http_code' => 200]);
                }

                // An origin that is not set up: the script is served, the beacon path is
                // not - the case the apply guarantee exists for.
                $origin = parse_url($url, \PHP_URL_SCHEME) . '://' . parse_url($url, \PHP_URL_HOST);

                return ($origins[$origin] ?? false)
                    ? new MockResponse("GIF89a\x01\x00\x01\x00", ['http_code' => 200, 'response_headers' => ['content-type' => 'image/gif']])
                    : new MockResponse('not found', ['http_code' => 404, 'response_headers' => ['content-type' => 'text/html']]);
            }
        );

        $client = new FastmonClient($httpClient);
        $store = new ConnectionStore($this->connectionRepository(), $systemConfig);
        $config = new ConfigResolver($systemConfig);

        return new CollectionModeService(
            $client,
            // A pasted key is the simplest credential to run these against: it needs no
            // token endpoint, so every response below is one this service asked for.
            new AccessTokenProvider(
                new FastmonOAuthClient($httpClient),
                $store,
                $config,
                new LockFactory(new InMemoryStore()),
                new NullLogger(),
            ),
            $store,
            $config,
            new EndpointChecker($httpClient, $store, $database),
            $systemConfig,
            new NullLogger(),
        );
    }
}
