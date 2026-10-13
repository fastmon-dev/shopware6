<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Doctrine\DBAL\Connection as DbalConnection;
use Fastmon\Collector\Collection\CollectionMode;
use Fastmon\Collector\Collection\DomainCheckResult;
use Fastmon\Collector\Collection\EndpointChecker;
use Fastmon\Collector\Collection\SiteCheckResult;
use Fastmon\Collector\Connection\ConnectionStore;
use Fastmon\Collector\Service\ConfigResolver;
use Fastmon\Collector\Tests\Unit\Fake\StoresConnection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class EndpointCheckerTest extends TestCase
{
    use StoresConnection;

    private const BUNDLE = 'var e="/c/colhash";!function(){}();';
    private const GIF = "GIF89a\x01\x00\x01\x00";

    /** @var list<string> */
    private array $requested = [];

    public function testAnOriginServingBothPathsIsReady(): void
    {
        $results = $this->check(CollectionMode::RELATIVE, ['https://shop.example'], $this->workingOrigin());

        self::assertCount(1, $results);
        self::assertTrue($results[0]->isReady());
        self::assertSame('', $results[0]->reason);
    }

    public function testTheCollectorProbeUsesAHashThatBelongsToNobody(): void
    {
        // Probing with the real hash would work and would write a synthetic pageview into
        // the customer's data every time someone pressed the button.
        $this->check(CollectionMode::RELATIVE, ['https://shop.example'], $this->workingOrigin());

        self::assertSame('https://shop.example/s/srchash.js', $this->requested[0]);
        self::assertSame('https://shop.example/c/' . str_repeat('0', 32), $this->requested[1]);
        self::assertStringNotContainsString('colhash', $this->requested[1]);
    }

    public function testAShopThatAnswersEverythingWithItsOwnPageIsNotReady(): void
    {
        // The failure that would otherwise slip through: a catch-all route or a soft 404
        // returns 200 with HTML. Requiring the collector hash inside the body is what
        // separates "fastmon answered" from "something answered".
        $results = $this->check(CollectionMode::RELATIVE, ['https://shop.example'], [
            new MockResponse('<!doctype html><title>Not found</title>', ['http_code' => 200]),
            new MockResponse('<!doctype html>', ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]),
        ]);

        self::assertFalse($results[0]->isReady());
        // A code, not a sentence: the administration turns it into a translated line.
        self::assertSame(DomainCheckResult::REASON_SCRIPT_FOREIGN, $results[0]->reason);
    }

    public function testAMissingCollectorPathIsReported(): void
    {
        $results = $this->check(CollectionMode::RELATIVE, ['https://shop.example'], [
            new MockResponse(self::BUNDLE, ['http_code' => 200]),
            new MockResponse('not found', ['http_code' => 404, 'response_headers' => ['content-type' => 'text/html']]),
        ]);

        self::assertTrue($results[0]->scriptOk);
        self::assertFalse($results[0]->collectorOk);
        self::assertSame(DomainCheckResult::REASON_COLLECTOR_STATUS, $results[0]->reason);
    }

    public function testAStatusOnTheScriptPathIsReportedWithItsCode(): void
    {
        // The number the merchant needs: 404 means nothing forwards the path, 502 means
        // something tries and fails. Different fixes.
        $results = $this->check(CollectionMode::RELATIVE, ['https://shop.example'], [
            new MockResponse('nope', ['http_code' => 404]),
            new MockResponse('nope', ['http_code' => 404, 'response_headers' => ['content-type' => 'text/html']]),
        ]);

        self::assertSame(DomainCheckResult::REASON_SCRIPT_STATUS, $results[0]->reason);
        self::assertSame('404', $results[0]->detail);
    }

    public function testAnUnreachableOriginIsAResultAndNotAnException(): void
    {
        // A storefront domain that does not resolve from the shop server is a finding to
        // show the merchant, not a 500 that hides the other origins' results.
        $results = $this->check(CollectionMode::RELATIVE, ['https://shop.example'], [
            new MockResponse([], ['error' => 'Could not resolve host']),
            new MockResponse([], ['error' => 'Could not resolve host']),
        ]);

        self::assertFalse($results[0]->isReady());
        self::assertSame(DomainCheckResult::REASON_SCRIPT_UNREACHABLE, $results[0]->reason);
        self::assertNotSame('', $results[0]->detail);
    }

    public function testRelativeChecksEveryStorefrontOrigin(): void
    {
        $results = $this->check(
            CollectionMode::RELATIVE,
            ['https://shop.example', 'https://shop.at'],
            [...$this->workingOrigin(), ...$this->workingOrigin()]
        );

        self::assertSame(
            ['https://shop.example', 'https://shop.at'],
            array_map(static fn ($r): string => $r->domain, $results)
        );
    }

    public function testCustomChecksOnlyTheGivenHost(): void
    {
        // A pinned host is one origin to prove, and it is not one of the shop's.
        $results = $this->check(
            CollectionMode::CUSTOM,
            ['https://shop.example'],
            $this->workingOrigin(),
            'metrics.example.com'
        );

        self::assertCount(1, $results);
        self::assertSame('https://metrics.example.com', $results[0]->domain);
        self::assertStringStartsWith('https://metrics.example.com/s/', $this->requested[0]);
    }

    public function testTheFastmonDefaultHasNothingToProve(): void
    {
        $results = $this->check(CollectionMode::FASTMON, ['https://shop.example'], []);

        self::assertSame([], $results);
        self::assertSame([], $this->requested, 'no origin should be contacted');
    }

    public function testOriginsAreReducedToDistinctSchemeHostAndPort(): void
    {
        // Two sales channels differing only by language path are one origin; probing both
        // would report the same answer twice.
        $checker = $this->checker(['https://shop.example/de', 'https://shop.example/en'], []);

        self::assertSame(['https://shop.example'], $checker->storefrontOrigins());
    }

    public function testABareHostnameBecomesAnHttpsOrigin(): void
    {
        // What people paste. http would have the beacon blocked as mixed content.
        $checker = $this->checker([], []);

        self::assertSame('https://metrics.example.com', $checker->normaliseOrigin('metrics.example.com'));
        self::assertSame('https://metrics.example.com', $checker->normaliseOrigin('https://metrics.example.com/path/'));
        self::assertSame('http://localhost:8080', $checker->normaliseOrigin('http://localhost:8080'));
        self::assertSame('', $checker->normaliseOrigin('   '));
    }

    public function testAPageCarryingTheSnippetProvesTheDomain(): void
    {
        $checker = $this->checker(['https://shop.example/de', 'https://shop.example/en'], [
            new MockResponse('<html><head><script defer src="/s/srchash.js"></script></head></html>', ['http_code' => 200]),
        ]);

        $results = $checker->checkSnippets();

        self::assertCount(1, $results);
        self::assertSame('shop.example', $results[0]->domain);
        self::assertTrue($results[0]->snippetOk);
        // The channel's own URL, path included: the root may belong to another channel.
        self::assertSame('https://shop.example/de', $results[0]->url);
        self::assertSame(['https://shop.example/de'], $this->requested);
    }

    public function testAPageWithoutTheSnippetIsNotProven(): void
    {
        // Served, reachable, and the plugin is not on it: another application's hash,
        // the plugin switched off for the channel, or a theme that dropped the block.
        $checker = $this->checker(['https://shop.example'], [
            new MockResponse('<html><script defer src="/s/otherhash.js"></script></html>', ['http_code' => 200]),
        ]);

        $results = $checker->checkSnippets();

        self::assertFalse($results[0]->snippetOk);
        self::assertSame(SiteCheckResult::REASON_PAGE_NO_SNIPPET, $results[0]->reason);
    }

    public function testASecondChannelOnTheSameHostGetsItsTurn(): void
    {
        // /de answers with a status, /en carries the snippet: one host, proven once.
        $checker = $this->checker(['https://shop.example/de', 'https://shop.example/en'], [
            new MockResponse('', ['http_code' => 503]),
            new MockResponse('<script src="https://cdn.fastmon.site/s/srchash.js"></script>', ['http_code' => 200]),
        ]);

        $results = $checker->checkSnippets();

        self::assertCount(1, $results);
        self::assertTrue($results[0]->snippetOk);
        self::assertSame('https://shop.example/en', $results[0]->url);
    }

    public function testAPageThatAnswersWithAStatusOrNotAtAllSaysWhich(): void
    {
        $checker = $this->checker(['https://staging.example', 'https://down.example'], [
            new MockResponse('', ['http_code' => 401]),
            new MockResponse('', ['error' => 'connection refused']),
        ]);

        $results = $checker->checkSnippets();

        self::assertSame(SiteCheckResult::REASON_PAGE_STATUS, $results[0]->reason);
        self::assertSame('401', $results[0]->detail);
        self::assertSame(SiteCheckResult::REASON_PAGE_UNREACHABLE, $results[1]->reason);
        self::assertStringContainsString('connection refused', $results[1]->detail);
    }

    public function testHostsAreLowercasedAndPortsDropped(): void
    {
        // A Site is keyed by hostname: fastmon lowercases, and a beacon carries no port.
        $checker = $this->checker(['https://Shop.Example:8443/de', 'http://shop.example'], [
            new MockResponse('<script src="/s/srchash.js">', ['http_code' => 200]),
        ]);

        self::assertSame(['shop.example' => ['https://Shop.Example:8443/de', 'http://shop.example']], $checker->storefrontUrls());
        self::assertSame('shop.example', $checker->checkSnippets()[0]->domain);
    }

    public function testAnUnprovisionedShopHasNoPagesToCheck(): void
    {
        self::assertSame([], $this->checker(['https://shop.example'], [], provisioned: false)->checkSnippets());
        self::assertSame([], $this->requested);
    }

    public function testAnUnprovisionedShopHasNothingToCheck(): void
    {
        $checker = $this->checker(['https://shop.example'], [], provisioned: false);

        self::assertSame([], $checker->check(CollectionMode::RELATIVE));
    }

    /**
     * @return list<MockResponse>
     */
    private function workingOrigin(): array
    {
        return [
            new MockResponse(self::BUNDLE, ['http_code' => 200]),
            new MockResponse(self::GIF, ['http_code' => 200, 'response_headers' => ['content-type' => 'image/gif']]),
        ];
    }

    /**
     * @param list<string>       $origins
     * @param list<MockResponse> $responses
     *
     * @return list<\Fastmon\Collector\Collection\DomainCheckResult>
     */
    private function check(CollectionMode $mode, array $origins, array $responses, string $custom = ''): array
    {
        return $this->checker($origins, $responses)->check($mode, $custom);
    }

    /**
     * @param list<string>       $origins
     * @param list<MockResponse> $responses
 *
 * The MockHttpClient callback is positional; the method is not needed to route by URL.
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
    private function checker(array $origins, array $responses, bool $provisioned = true): EndpointChecker
    {
        $client = new MockHttpClient(function (string $method, string $url) use (&$responses): MockResponse {
            $this->requested[] = $url;

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        if ($provisioned) {
            $this->config[ConfigResolver::DOMAIN . 'sourceHash'] = 'srchash';
            $this->config[ConfigResolver::DOMAIN . 'collectorHash'] = 'colhash';
        }

        $systemConfig = $this->systemConfig();

        $database = $this->createMock(DbalConnection::class);
        $database->method('fetchFirstColumn')->willReturn($origins);

        return new EndpointChecker($client, new ConnectionStore($this->connectionRepository(), $systemConfig), $database);
    }
}
