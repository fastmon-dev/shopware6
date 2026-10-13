<?php declare(strict_types=1);

namespace Fastmon\Collector\Provisioning;

use Fastmon\Collector\Api\FastmonApiException;
use Fastmon\Collector\Api\FastmonClient;
use Fastmon\Collector\Api\FastmonOrganizationNotApprovedException;
use Fastmon\Collector\Api\FastmonPermissionDeniedException;
use Fastmon\Collector\Api\FastmonSiteExistsException;
use Fastmon\Collector\Api\FastmonUnauthorizedException;
use Fastmon\Collector\Collection\EndpointChecker;
use Fastmon\Collector\Connection\AccessTokenProvider;
use Fastmon\Collector\Connection\ConnectionStore;
use Fastmon\Collector\FastmonCollectorException;
use Fastmon\Collector\Service\ConfigResolver;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;

/**
 * Makes every storefront domain a Site of the linked application.
 *
 * fastmon's default site policy keeps a domain it has not been told about out of the
 * data and puts it on a suggestion list in the dashboard instead. The shop knows its
 * domains before the first visitor does, so it tells fastmon: whatever the active
 * storefront sales channels serve and fastmon does not list yet is created, once, at
 * link time, when a sales channel is saved, and whenever the merchant asks.
 *
 * Add only. A domain that left the shop may still carry history worth keeping, and
 * whether to retire it is a decision for the dashboard, not a side effect of an admin
 * save. The dashboard's own suggestion list stays what it is for: hostnames the shop
 * never configured, which is exactly the set the merchant should look at by hand.
 */
#[WithMonologChannel('fastmon_collector')]
final class SiteSynchroniser
{
    public function __construct(
        private readonly FastmonClient $client,
        private readonly AccessTokenProvider $tokens,
        private readonly ConnectionStore $store,
        private readonly ConfigResolver $config,
        private readonly EndpointChecker $storefront,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Create what is missing and say what happened to each domain.
     *
     * `created` and `existing` are the two outcomes of a domain fastmon accepted;
     * `skipped` names the ones it refused, with its reason, so the panel can show
     * "192.168.0.10: not a domain" instead of failing the whole run over the dev host.
     * A refusal that is about the connection rather than a domain (no credential, no
     * permission, organisation not approved) propagates: nothing about the next domain
     * would go differently.
     *
     * @return array{created: list<string>, existing: list<string>, skipped: list<array{domain: string, reason: string}>}
     */
    public function sync(): array
    {
        $connection = $this->store->load();

        if ($connection->applicationId === '') {
            throw FastmonCollectorException::noApplicationLinked();
        }

        $applicationId = $connection->applicationId;
        $baseUrl = $this->config->apiBaseUrl();

        $known = [];

        foreach ($this->tokens->call(fn (string $token): array => $this->client->applicationSites($baseUrl, $token, $applicationId)) as $site) {
            $known[$site['domain']] = true;
        }

        $result = ['created' => [], 'existing' => [], 'skipped' => []];

        foreach ($this->storefront->storefrontHostnames() as $hostname) {
            if (isset($known[$hostname])) {
                $result['existing'][] = $hostname;

                continue;
            }

            try {
                $site = $this->tokens->call(fn (string $token): array => $this->client->createApplicationSite($baseUrl, $token, $applicationId, $hostname));
                $result['created'][] = $site['domain'] !== '' ? $site['domain'] : $hostname;
                $this->logger->info(sprintf('fastmon: registered %s as a site of application %s', $hostname, $applicationId));
            } catch (FastmonSiteExistsException) {
                // Listed under another spelling, or added since the list was read.
                $result['existing'][] = $hostname;
            } catch (FastmonUnauthorizedException | FastmonPermissionDeniedException | FastmonOrganizationNotApprovedException $e) {
                throw $e;
            } catch (FastmonApiException $e) {
                $result['skipped'][] = ['domain' => $hostname, 'reason' => $e->getMessage()];
                $this->logger->warning(sprintf('fastmon: %s was not registered as a site: %s', $hostname, $e->getMessage()));
            }
        }

        return $result;
    }

    /**
     * The sync as a step after something that already succeeded.
     *
     * Right after a link the application is stored and the storefront emits for it; a
     * sync that fails then is worth a sentence in the panel, not a failed link that
     * would make the merchant link again. So a merchant-facing refusal becomes the
     * `error` field and the link's response stays a success. Anything else propagates,
     * because a programming error belongs in the log, not in a sentence.
     *
     * @return array{created: list<string>, existing: list<string>, skipped: list<array{domain: string, reason: string}>, error?: string}
     */
    public function syncAfterLink(): array
    {
        try {
            return $this->sync();
        } catch (FastmonApiException | FastmonCollectorException $e) {
            $this->logger->warning('fastmon: the domains were not synced after linking: ' . $e->getMessage());

            return ['created' => [], 'existing' => [], 'skipped' => [], 'error' => $e->getMessage()];
        }
    }
}
