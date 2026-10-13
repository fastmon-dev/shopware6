<?php declare(strict_types=1);

namespace Fastmon\Collector\Collection;

use Doctrine\DBAL\Connection as Database;
use Fastmon\Collector\Connection\ConnectionStore;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Answers one question: does `/s/<hash>.js` and `/c/<hash>` actually answer where a
 * given collection mode would point the storefront?
 *
 * The plugin does not forward those paths itself, it only checks whether the merchant
 * set the forwarding up. A beacon per pageview through PHP-FPM would put fastmon's
 * latency in front of the shop's worker pool, which is rule 2 in `AGENTS.md`.
 *
 * Three things are decided in the document below, and each one reads as an oversight
 * from here: which origins get probed, why it is these two probes, and why an origin in
 * a private range is probed rather than refused.
 *
 * @see docs/collection-endpoint-probe.md
 */
final class EndpointChecker
{
    /**
     * A syntactically valid collector hash that cannot resolve to an application, so the
     * probe can never record anything.
     */
    private const UNROUTABLE_HASH = '00000000000000000000000000000000';

    /**
     * Short: this runs while an admin waits, once per origin. An origin that needs longer
     * than this to answer its own storefront has a problem worth reporting anyway.
     */
    private const TIMEOUT_SECONDS = 5;

    public function __construct(
        #[Autowire(service: 'fastmon_collector.http_client')]
        private readonly HttpClientInterface $httpClient,
        private readonly ConnectionStore $store,
        // Read with SQL rather than through the DAL, and Shopware's own analysis rules
        // are the reason: a repository read needs a `Context`, and
        // `Context::createDefaultContext()` is refused outside a CLI command, while
        // threading one from the controller through two services would be plumbing for a
        // single column. `sales_channel_domain.url` has been that column since 6.0.
        private readonly Database $database,
    ) {
    }

    /**
     * @return list<DomainCheckResult>
     */
    public function check(CollectionMode $mode, string $customDomain = ''): array
    {
        $connection = $this->store->load();

        if (!$connection->isProvisioned() || !$mode->needsProof()) {
            return [];
        }

        $origins = $mode === CollectionMode::CUSTOM
            ? array_filter([$this->normaliseOrigin($customDomain)])
            : $this->storefrontOrigins();

        $results = [];

        foreach ($origins as $origin) {
            $results[] = $this->checkOrigin($origin, $connection->sourceHash, $connection->collectorHash);
        }

        return $results;
    }

    /**
     * Every distinct origin the snippet can actually end up on.
     *
     * Three filters, and each one exists because of a domain that would otherwise fail the
     * probe forever and block the switch for the whole shop:
     *
     *   - **Storefront channels only.** A headless or product-comparison channel carries a
     *     domain too (`default.headless0` ships with every install), and no browser ever
     *     loads a storefront page there.
     *   - **Active channels only.** A disabled channel serves nothing.
     *   - **Distinct origins.** Two channels differing only by language path are one
     *     origin; probing both would report the same answer twice.
     *
     * Read through DBAL rather than the DAL. A DAL read needs a `Context`, and none fits:
     * `Context::createDefaultContext()` is refused outside a CLI command by Shopware's
     * analysis rules (`shopware-cli extension validate --check-against highest`), the
     * system context `ConnectionStore` builds is justified there by an entity that admits
     * nothing else, and threading the request's context from the controller through two
     * services would be plumbing for a single column. The two columns the join reads have
     * not changed since 6.0.
     *
     * @return list<string>
     */
    public function storefrontOrigins(): array
    {
        $origins = [];

        foreach ($this->storefrontDomainUrls() as $url) {
            // Several channels can differ only by path, and two domains on one host are
            // one origin to probe: keyed rather than appended.
            $origin = $this->normaliseOrigin($url);

            if ($origin !== '') {
                $origins[$origin] = true;
            }
        }

        return array_keys($origins);
    }

    /**
     * @return list<string>
     */
    private function storefrontDomainUrls(): array
    {
        // Storefronts only, and only the ones that are switched on. A headless channel
        // serves no pages, so nothing there could load the tracker.
        /** @var list<string> $urls */
        $urls = $this->database->fetchFirstColumn(
            'SELECT scd.url
             FROM sales_channel_domain scd
             INNER JOIN sales_channel sc ON sc.id = scd.sales_channel_id
             WHERE sc.active = 1 AND sc.type_id = :storefront',
            ['storefront' => Uuid::fromHexToBytes(Defaults::SALES_CHANNEL_TYPE_STOREFRONT)]
        );

        return $urls;
    }

    /**
     * Does a page on each storefront domain carry this application's snippet?
     *
     * One result per hostname, which is what a fastmon Site is keyed by: a beacon
     * carries no scheme and no port, so `https://shop.example:8443` and
     * `http://shop.example` are one Site. The page fetched is the sales channel's own
     * URL, path included, because the root of a host can belong to another channel or
     * to nothing at all when every channel lives under a language path. A host with
     * several channels is tried until one of them shows the snippet.
     *
     * Nothing to fetch for a shop that is not linked: there is no hash to look for.
     *
     * @return list<SiteCheckResult>
     */
    public function checkSnippets(): array
    {
        $connection = $this->store->load();

        if (!$connection->isProvisioned()) {
            return [];
        }

        $results = [];

        foreach ($this->storefrontUrls() as $host => $urls) {
            $results[] = $this->checkSnippet($host, $urls, $connection->sourceHash);
        }

        return $results;
    }

    /**
     * The storefront domain URLs, grouped by lowercased hostname. Same rows as
     * `storefrontOrigins()`, kept whole: the page probe needs the path.
     *
     * @return array<string, list<string>>
     */
    public function storefrontUrls(): array
    {
        $byHost = [];

        foreach ($this->storefrontDomainUrls() as $url) {
            $origin = $this->normaliseOrigin($url);
            $host = $origin === '' ? '' : (string) parse_url($origin, \PHP_URL_HOST);

            if ($host !== '') {
                $byHost[strtolower($host)][] = $url;
            }
        }

        return $byHost;
    }

    /**
     * Scheme, host and port of a URL, with everything else dropped. Empty when there is no
     * host to speak of, which is what a merchant typing a bare path into the domain field
     * produces. (The headless channel's `default.headless0` would pass this - it is the
     * storefront-only filter in `storefrontOrigins()` that keeps it out.)
     */
    public function normaliseOrigin(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        // A bare hostname is what people paste. https, matching how fastmon normalises a
        // collector endpoint - beacons are blocked as mixed content on an http origin.
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);

        if (!\is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $origin = ($parts['scheme'] ?? 'https') . '://' . $parts['host'];

        return isset($parts['port']) ? $origin . ':' . $parts['port'] : $origin;
    }
    /**
     * Fetch the channel's pages until one carries the script tag the storefront template
     * renders for this application. The tag's path is the one thing on the page that
     * nothing but this plugin, linked to this application, can have put there.
     *
     * Looked for in the HTML rather than requested from `/s/`: that probe answers
     * "would the tracker load if asked", this one answers "is it being asked", which is
     * the question a domain nobody added the snippet to fails.
     *
     * @param list<string> $urls
     */
    private function checkSnippet(string $host, array $urls, string $sourceHash): SiteCheckResult
    {
        $marker = '/s/' . $sourceHash . '.js';
        $reason = '';
        $detail = '';
        $last = '';

        foreach ($urls as $url) {
            $last = $url;

            try {
                $page = $this->httpClient->request('GET', $url, [
                    'timeout' => self::TIMEOUT_SECONDS,
                    'headers' => ['Accept' => 'text/html'],
                ]);

                if ($page->getStatusCode() !== 200) {
                    $reason = SiteCheckResult::REASON_PAGE_STATUS;
                    $detail = (string) $page->getStatusCode();

                    continue;
                }

                if (str_contains($page->getContent(false), $marker)) {
                    return new SiteCheckResult($host, $url, true);
                }

                $reason = SiteCheckResult::REASON_PAGE_NO_SNIPPET;
                $detail = '';
            } catch (\Throwable $e) {
                $reason = SiteCheckResult::REASON_PAGE_UNREACHABLE;
                $detail = $e->getMessage();
            }
        }

        return new SiteCheckResult($host, $last, false, $reason, $detail);
    }

    /**
     * Two probes with four outcomes each; the reason codes are the point and are enumerated in DomainCheckResult.
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     */
    private function checkOrigin(string $origin, string $sourceHash, string $collectorHash): DomainCheckResult
    {
        $scriptOk = false;
        $collectorOk = false;
        $reason = '';
        $detail = '';

        try {
            $script = $this->httpClient->request('GET', $origin . '/s/' . $sourceHash . '.js', [
                'timeout' => self::TIMEOUT_SECONDS,
                'headers' => ['Accept' => 'application/javascript'],
            ]);

            if ($script->getStatusCode() === 200) {
                // The endpoint fastmon bakes into the bundle. Nothing but fastmon's own
                // render of THIS application's bundle can contain it.
                $scriptOk = $collectorHash !== '' && str_contains($script->getContent(false), '/c/' . $collectorHash);

                if (!$scriptOk) {
                    $reason = DomainCheckResult::REASON_SCRIPT_FOREIGN;
                }
            } else {
                $reason = DomainCheckResult::REASON_SCRIPT_STATUS;
                $detail = (string) $script->getStatusCode();
            }
        } catch (\Throwable $e) {
            $reason = DomainCheckResult::REASON_SCRIPT_UNREACHABLE;
            $detail = $e->getMessage();
        }

        try {
            $collector = $this->httpClient->request('GET', $origin . '/c/' . self::UNROUTABLE_HASH, [
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            $contentType = (string) ($collector->getHeaders(false)['content-type'][0] ?? '');
            $collectorOk = $collector->getStatusCode() === 200
                && str_contains(mb_strtolower($contentType), 'image/gif');

            if (!$collectorOk && $reason === '') {
                $reason = DomainCheckResult::REASON_COLLECTOR_STATUS;
                $detail = (string) $collector->getStatusCode();
            }
        } catch (\Throwable $e) {
            if ($reason === '') {
                $reason = DomainCheckResult::REASON_COLLECTOR_UNREACHABLE;
                $detail = $e->getMessage();
            }
        }

        return new DomainCheckResult($origin, $scriptOk, $collectorOk, $reason, $detail);
    }
}
