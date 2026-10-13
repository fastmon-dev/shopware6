<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\Api\FastmonClient;
use Fastmon\Collector\Api\FastmonOAuthClient;
use Fastmon\Collector\Api\FastmonPermissionDeniedException;
use Fastmon\Collector\Collection\SiteCheckResult;
use Fastmon\Collector\Connection\AccessTokenProvider;
use Fastmon\Collector\Connection\ConnectionStore;
use Fastmon\Collector\FastmonCollectorException;
use Fastmon\Collector\Provisioning\SiteRegistrar;
use Fastmon\Collector\Service\ConfigResolver;
use Fastmon\Collector\Tests\Unit\Fake\StoresConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class SiteRegistrarTest extends TestCase
{
    use StoresConnection;

    /** @var list<array{method: string, url: string, body: array<mixed>}> */
    private array $sent = [];

    protected function setUp(): void
    {
        // A pasted key and a linked application: the registration itself is under test.
        $this->row = ['manualToken' => 'fmk_live', 'applicationId' => 'app-1'];
    }

    public function testOnlyProvenDomainsFastmonDoesNotListAreCreated(): void
    {
        $results = $this->registrar([
            $this->sites(['shop.example']),
            $this->site('outlet.example', 201),
        ])->register([
            $this->proven('shop.example'),
            $this->proven('outlet.example'),
            $this->unproven('staging.example'),
        ]);

        self::assertSame(SiteCheckResult::SITE_EXISTING, $results[0]->site);
        self::assertSame(SiteCheckResult::SITE_CREATED, $results[1]->site);
        // Not proven, not registered: fastmon would wait for beacons that cannot come.
        self::assertSame('', $results[2]->site);
        // One list, one create.
        self::assertCount(2, $this->sent);
        self::assertSame('POST', $this->sent[1]['method']);
        self::assertStringEndsWith('/v1/applications/app-1/sites', $this->sent[1]['url']);
        self::assertSame(['domain' => 'outlet.example'], $this->sent[1]['body']);
    }

    public function testADomainFastmonHasUnderAnotherNameCountsAsPresent(): void
    {
        // Listed in punycode, configured in unicode: the list misses it, the create
        // answers 409, and the merchant reads "registered" rather than an error.
        $results = $this->registrar([
            $this->sites(['xn--shp-example']),
            $this->error('site_already_exists', 'already there', 409),
        ])->register([$this->proven('shöp.example')]);

        self::assertSame(SiteCheckResult::SITE_EXISTING, $results[0]->site);
    }

    public function testADomainFastmonRefusesIsFailedWithItsReasonAndTheRestGoesOn(): void
    {
        // The dev host is an IP address and fastmon takes no IP addresses. That is a
        // line beside that domain, not a reason for the real one to go unregistered.
        $results = $this->registrar([
            $this->sites([]),
            $this->error('validation_error', 'Invalid domain format.', 422),
            $this->site('shop.example', 201),
        ])->register([$this->proven('192.168.0.10'), $this->proven('shop.example')]);

        self::assertSame(SiteCheckResult::SITE_FAILED, $results[0]->site);
        self::assertStringContainsString('Invalid domain format.', $results[0]->siteDetail);
        self::assertSame(SiteCheckResult::SITE_CREATED, $results[1]->site);
    }

    public function testAMissingPermissionStopsTheRunRatherThanRepeatingForEveryDomain(): void
    {
        // Nothing about the second domain would go differently, and the panel maps this
        // exception to the sentence that names the scope.
        $registrar = $this->registrar([
            $this->sites([]),
            $this->error('permission_denied', 'Missing permission: site:write.', 403, ['permission' => 'site:write']),
        ]);

        try {
            $registrar->register([$this->proven('a.example'), $this->proven('b.example')]);
            self::fail('expected a FastmonPermissionDeniedException');
        } catch (FastmonPermissionDeniedException $e) {
            self::assertSame('site:write', $e->permission);
            self::assertCount(2, $this->sent);
        }
    }

    public function testNothingIsAskedWhenNoDomainWasProven(): void
    {
        // An empty MockHttpClient throws on the first request, so this also proves that
        // not even the list was read.
        $results = $this->registrar([])->register([$this->unproven('shop.example')]);

        self::assertSame('', $results[0]->site);
    }

    public function testNothingIsAskedWithoutALinkedApplication(): void
    {
        $this->row = ['manualToken' => 'fmk_live'];

        $this->expectException(FastmonCollectorException::class);
        $this->registrar([])->register([$this->proven('shop.example')]);
    }

    private function proven(string $domain): SiteCheckResult
    {
        return new SiteCheckResult($domain, 'https://' . $domain, true);
    }

    private function unproven(string $domain): SiteCheckResult
    {
        return new SiteCheckResult($domain, 'https://' . $domain, false, SiteCheckResult::REASON_PAGE_NO_SNIPPET);
    }

    /**
     * @param list<string> $domains
     */
    private function sites(array $domains): MockResponse
    {
        $data = [];

        foreach ($domains as $i => $domain) {
            $data[] = ['id' => 's' . $i, 'domain' => $domain, 'name' => $domain];
        }

        return new MockResponse(json_encode(['data' => $data], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }

    private function site(string $domain, int $status): MockResponse
    {
        return new MockResponse(
            json_encode(['id' => 'new', 'domain' => $domain, 'name' => $domain], \JSON_THROW_ON_ERROR),
            ['http_code' => $status]
        );
    }

    /**
     * @param array<string, string> $details
     */
    private function error(string $code, string $message, int $status, array $details = []): MockResponse
    {
        return new MockResponse(json_encode([
            'error' => ['code' => $code, 'message' => $message, 'details' => $details],
        ], \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }

    /**
     * @param list<MockResponse> $api
     */
    private function registrar(array $api): SiteRegistrar
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$api): MockResponse {
            $body = $options['body'] ?? '';
            $this->sent[] = [
                'method' => $method,
                'url' => $url,
                'body' => \is_string($body) && $body !== '' ? (array) json_decode($body, true) : [],
            ];

            $next = array_shift($api);
            self::assertNotNull($next, 'more requests than responses: ' . $method . ' ' . $url);

            return $next;
        });

        $systemConfig = $this->systemConfig();
        $store = new ConnectionStore($this->connectionRepository(), $systemConfig);
        $config = new ConfigResolver($systemConfig);

        return new SiteRegistrar(
            new FastmonClient($http),
            new AccessTokenProvider(
                new FastmonOAuthClient(new MockHttpClient([])),
                $store,
                $config,
                new LockFactory(new InMemoryStore()),
                new NullLogger()
            ),
            $store,
            $config,
            new NullLogger(),
        );
    }
}
