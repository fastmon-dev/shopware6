<?php declare(strict_types=1);

namespace Fastmon\Collector\Provisioning;

use Fastmon\Collector\Api\FastmonApiException;
use Fastmon\Collector\Api\FastmonClient;
use Fastmon\Collector\Api\FastmonOrganizationNotApprovedException;
use Fastmon\Collector\Api\FastmonPermissionDeniedException;
use Fastmon\Collector\Api\FastmonSiteExistsException;
use Fastmon\Collector\Api\FastmonUnauthorizedException;
use Fastmon\Collector\Collection\SiteCheckResult;
use Fastmon\Collector\Connection\AccessTokenProvider;
use Fastmon\Collector\Connection\ConnectionStore;
use Fastmon\Collector\FastmonCollectorException;
use Fastmon\Collector\Service\ConfigResolver;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;

/**
 * Makes a storefront domain a Site of the linked application, once the check has seen
 * the snippet on it.
 *
 * fastmon's default site policy keeps a domain it has not been told about out of the
 * data and puts it on a suggestion list in the dashboard instead. The plugin can do
 * better than a suggestion: when the setup check fetches a storefront page and finds
 * this application's snippet in it, that domain is proven to collect, and it is
 * registered on the spot. "Test passed" is the whole trigger; nothing registers a
 * domain on a link, on a sales channel save or on a timer, because none of those has
 * seen a page.
 *
 * Add only. A domain that left the shop may still carry history worth keeping, and
 * whether to retire it is a decision for the dashboard, not a side effect of a check.
 * The dashboard's suggestion list stays what it is for: hostnames the shop never
 * configured, which is exactly the set the merchant should look at by hand.
 */
#[WithMonologChannel('fastmon_collector')]
final class SiteRegistrar
{
    public function __construct(
        private readonly FastmonClient $client,
        private readonly AccessTokenProvider $tokens,
        private readonly ConnectionStore $store,
        private readonly ConfigResolver $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Register every result that found the snippet, and say how it went on each.
     *
     * fastmon's list is read first, so a domain it has is reported as present without a
     * request; a domain it has under another spelling (punycode, say) answers 409 on
     * the create and is reported the same way. A domain fastmon refuses is `failed`
     * with fastmon's reason and does not stop the others. A refusal about the
     * connection rather than a domain (no credential, no permission, organisation not
     * approved) propagates: nothing about the next domain would go differently.
     *
     * @param list<SiteCheckResult> $checks
     *
     * @return list<SiteCheckResult>
     */
    public function register(array $checks): array
    {
        $connection = $this->store->load();

        if ($connection->applicationId === '') {
            throw FastmonCollectorException::noApplicationLinked();
        }

        $candidates = array_filter($checks, static fn (SiteCheckResult $r): bool => $r->snippetOk);

        if ($candidates === []) {
            return $checks;
        }

        $applicationId = $connection->applicationId;
        $baseUrl = $this->config->apiBaseUrl();
        $known = [];

        foreach ($this->tokens->call(fn (string $token): array => $this->client->applicationSites($baseUrl, $token, $applicationId)) as $site) {
            $known[$site['domain']] = true;
        }

        $out = [];

        foreach ($checks as $check) {
            $out[] = $check->snippetOk
                ? $this->registerOne($check, $known, $baseUrl, $applicationId)
                : $check;
        }

        return $out;
    }

    /**
     * @param array<string, true> $known
     */
    private function registerOne(SiteCheckResult $check, array $known, string $baseUrl, string $applicationId): SiteCheckResult
    {
        if (isset($known[$check->domain])) {
            return $check->withSite(SiteCheckResult::SITE_EXISTING);
        }

        try {
            $this->tokens->call(fn (string $token): array => $this->client->createApplicationSite($baseUrl, $token, $applicationId, $check->domain));
            $this->logger->info(sprintf('fastmon: registered %s as a site of application %s', $check->domain, $applicationId));

            return $check->withSite(SiteCheckResult::SITE_CREATED);
        } catch (FastmonSiteExistsException) {
            return $check->withSite(SiteCheckResult::SITE_EXISTING);
        } catch (FastmonUnauthorizedException | FastmonPermissionDeniedException | FastmonOrganizationNotApprovedException $e) {
            throw $e;
        } catch (FastmonApiException $e) {
            $this->logger->warning(sprintf('fastmon: %s was not registered as a site: %s', $check->domain, $e->getMessage()));

            return $check->withSite(SiteCheckResult::SITE_FAILED, $e->getMessage());
        }
    }
}
