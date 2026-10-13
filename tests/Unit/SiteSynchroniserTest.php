<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Doctrine\DBAL\Connection as DbalConnection;
use Fastmon\Collector\Api\FastmonClient;
use Fastmon\Collector\Api\FastmonOAuthClient;
use Fastmon\Collector\Api\FastmonPermissionDeniedException;
use Fastmon\Collector\Collection\EndpointChecker;
use Fastmon\Collector\Connection\AccessTokenProvider;
use Fastmon\Collector\Connection\ConnectionStore;
use Fastmon\Collector\FastmonCollectorException;
use Fastmon\Collector\Provisioning\SiteSynchroniser;
use Fastmon\Collector\Service\ConfigResolver;
use Fastmon\Collector\Tests\Unit\Fake\StoresConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class SiteSynchroniserTest extends TestCase
{
    use StoresConnection;

    /** @var list<array{method: string, url: string, body: array<mixed>}> */
    private array $sent = [];

    protected function setUp(): void
    {
        // A pasted key and a linked application: the sync itself is what is under test.
        $this->row = ['manualToken' => 'fmk_live', 'applicationId' => 'app-1'];
    }

    public function testOnlyTheDomainsFastmonDoesNotListAreCreated(): void
    {
        $sync = $this->synchroniser(
            ['https://shop.example/de', 'https://shop.example/en', 'https://Outlet.Example:8443'],
            [
                $this->sites(['shop.example']),
                $this->site('outlet.example', 201),
            ]
        );

        $result = $sync->sync();

        self::assertSame(['created' => ['outlet.example'], 'existing' => ['shop.example'], 'skipped' => []], $result);
        // One list, one create: nothing is asked for the domain fastmon already had, and
        // the two language channels on one host are one site.
        self::assertCount(2, $this->sent);
        self::assertSame('POST', $this->sent[1]['method']);
        self::assertStringEndsWith('/v1/applications/app-1/sites', $this->sent[1]['url']);
        self::assertSame(['domain' => 'outlet.example'], $this->sent[1]['body']);
    }

    public function testADomainFastmonHasUnderAnotherNameCountsAsPresent(): void
    {
        // Listed in punycode, configured in unicode: the list misses it, the create
        // answers 409, and the merchant reads "already there" rather than an error.
        $sync = $this->synchroniser(['https://shop.example'], [
            $this->sites(['xn--shp-example']),
            $this->error('site_already_exists', 'already there', 409),
        ]);

        self::assertSame(['created' => [], 'existing' => ['shop.example'], 'skipped' => []], $sync->sync());
    }

    public function testADomainFastmonRefusesIsSkippedWithItsReasonAndTheRestGoesOn(): void
    {
        // The dev host is an IP address and fastmon takes no IP addresses. That is a
        // line in the panel, not a reason for the real domain to go unregistered.
        $sync = $this->synchroniser(['http://192.168.0.10', 'https://shop.example'], [
            $this->sites([]),
            $this->error('validation_error', 'Invalid domain format.', 422),
            $this->site('shop.example', 201),
        ]);

        $result = $sync->sync();

        self::assertSame(['shop.example'], $result['created']);
        self::assertCount(1, $result['skipped']);
        self::assertSame('192.168.0.10', $result['skipped'][0]['domain']);
        self::assertStringContainsString('Invalid domain format.', $result['skipped'][0]['reason']);
    }

    public function testAMissingPermissionStopsTheRunRatherThanRepeatingForEveryDomain(): void
    {
        // Nothing about the second domain would go differently, and the panel maps this
        // exception to the sentence that names the scope.
        $sync = $this->synchroniser(['https://a.example', 'https://b.example'], [
            $this->sites([]),
            $this->error('permission_denied', 'Missing permission: site:write.', 403, ['permission' => 'site:write']),
        ]);

        try {
            $sync->sync();
            self::fail('expected a FastmonPermissionDeniedException');
        } catch (FastmonPermissionDeniedException $e) {
            self::assertSame('site:write', $e->permission);
            self::assertCount(2, $this->sent);
        }
    }

    public function testNothingIsAskedWithoutALinkedApplication(): void
    {
        $this->row = ['manualToken' => 'fmk_live'];

        $this->expectException(FastmonCollectorException::class);
        // An empty MockHttpClient throws on the first request, so this also proves that
        // no call was made.
        $this->synchroniser(['https://shop.example'], [])->sync();
    }

    public function testAfterALinkARefusalIsAFieldAndNotAFailure(): void
    {
        // The link already succeeded; failing the response over the domains would make
        // the merchant link again for something they can fix with one button.
        $result = $this->synchroniser(['https://shop.example'], [
            $this->error('permission_denied', 'Missing permission: site:write.', 403, ['permission' => 'site:write']),
        ])->syncAfterLink();

        self::assertSame([], $result['created']);
        self::assertSame('Missing permission: site:write.', $result['error'] ?? null);
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
     * @param list<string> $salesChannelUrls what `sales_channel_domain.url` holds
     * @param list<MockResponse> $api
     */
    private function synchroniser(array $salesChannelUrls, array $api): SiteSynchroniser
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

        $database = $this->createMock(DbalConnection::class);
        $database->method('fetchFirstColumn')->willReturn($salesChannelUrls);

        return new SiteSynchroniser(
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
            new EndpointChecker(new MockHttpClient([]), $store, $database),
            new NullLogger(),
        );
    }
}
