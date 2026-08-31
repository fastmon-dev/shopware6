<?php declare(strict_types=1);

namespace Fastmon\Collector\Provisioning;

use Fastmon\Collector\Api\FastmonClient;
use Fastmon\Collector\Connection\AccessTokenProvider;
use Fastmon\Collector\Connection\ConnectionStore;
use Fastmon\Collector\FastmonCollectorException;
use Fastmon\Collector\Service\ConfigResolver;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;

/**
 * Points the storefront at a fastmon application.
 *
 * ## Why there is no sales-channel loop here
 *
 * There used to be one. The fastmon Shopware app creates a *Site* per sales channel: it
 * reads every channel and its domains through the Admin API, creates one site for each,
 * and writes a separate tracker id into that channel's `system_config`. Adding a domain
 * meant going back into the wizard, and a channel with two domains had to pick one.
 *
 * The Application model replaced that. An application is one embed whose collection
 * config covers every domain it runs on, and with `site_policy: auto` the domain a
 * beacon came from is turned into a Site on arrival. So one application, one snippet,
 * every sales channel and every domain covered - including the ones added next month,
 * without anyone opening this panel again.
 *
 * What is left for this class is choosing which application, and remembering the answer.
 */
#[WithMonologChannel('fastmon_collector')]
final class ApplicationProvisioner
{
    /**
     * Naming a new application after the shop is the one piece of context fastmon cannot
     * infer, and a merchant with several shops needs it to tell them apart in the
     * dashboard.
     */
    public const DEFAULT_NAME = 'Shopware';

    /**
     * The PII-free posture: pseudonymous stitch, fetch/XHR aggregates, query key names
     * and error stack frames, but no message text, no query values and no session write.
     * The right default for a shop that has not thought about it yet, and the one that
     * needs no consent banner entry.
     */
    public const DEFAULT_PRESET = 'standard';

    public function __construct(
        private readonly FastmonClient $client,
        private readonly AccessTokenProvider $tokens,
        private readonly ConnectionStore $store,
        private readonly ConfigResolver $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function organizations(): array
    {
        return $this->tokens->call(
            fn (string $token): array => $this->client->organizations($this->config->apiBaseUrl(), $token)
        );
    }

    /**
     * @return list<array{id: string, name: string, trackerId: string, pixelId: string, environment: string, siteCount: int, collectorMode: string, collectorEndpoint: string}>
     */
    public function applications(string $organizationId): array
    {
        return $this->tokens->call(fn (string $token): array => $this->client->applications(
            $this->config->apiBaseUrl(),
            $token,
            $organizationId
        ));
    }

    /**
     * Create an application and point the storefront at it.
     *
     * @return array{id: string, name: string, trackerId: string, pixelId: string, environment: string, siteCount: int, collectorMode: string, collectorEndpoint: string}
     */
    public function create(string $organizationId, string $name, string $environment, string $preset): array
    {
        $application = $this->tokens->call(fn (string $token): array => $this->client->createApplication(
            $this->config->apiBaseUrl(),
            $token,
            $organizationId,
            $name !== '' ? $name : self::DEFAULT_NAME,
            $environment !== '' ? $environment : 'prod',
            $preset !== '' ? $preset : self::DEFAULT_PRESET,
        ));

        $this->persist($organizationId, $application);
        $this->logger->info(sprintf(
            'fastmon: created application %s (%s) and linked the storefront',
            $application['name'],
            $application['id']
        ));

        return $application;
    }

    /**
     * Point the storefront at an application that already exists - a second shop feeding
     * one dashboard, or a re-link after someone disconnected.
     *
     * The hashes are re-read rather than taken from whatever the browser posted, so a
     * stale list in an open admin tab cannot write a tracker id that no longer exists.
     *
     * @return array{id: string, name: string, trackerId: string, pixelId: string, environment: string, siteCount: int, collectorMode: string, collectorEndpoint: string}
     */
    public function attach(string $organizationId, string $applicationId): array
    {
        $application = $this->tokens->call(fn (string $token): array => $this->client->fetchApplication(
            $this->config->apiBaseUrl(),
            $token,
            $applicationId
        ));

        if ($application['trackerId'] === '') {
            throw FastmonCollectorException::applicationWithoutTrackerId();
        }

        $this->persist($organizationId, $application);
        $this->logger->info(sprintf(
            'fastmon: linked the storefront to application %s (%s)',
            $application['name'],
            $application['id']
        ));

        return $application;
    }

    /**
     * The domains fastmon has seen this application on.
     *
     * @return list<array{id: string, domain: string, name: string}>
     */
    public function sites(): array
    {
        $connection = $this->store->load();

        if ($connection->applicationId === '') {
            return [];
        }

        return $this->tokens->call(fn (string $token): array => $this->client->applicationSites(
            $this->config->apiBaseUrl(),
            $token,
            $connection->applicationId
        ));
    }

    /**
     * @param array{id: string, name: string, trackerId: string, pixelId: string, environment: string, siteCount: int, collectorMode: string, collectorEndpoint: string} $application
     */
    private function persist(string $organizationId, array $application): void
    {
        $this->store->saveOrganization($organizationId, $this->organizationName($organizationId));
        $this->store->saveApplication(
            $organizationId,
            $application['id'],
            $application['trackerId'],
            $application['pixelId'],
        );
    }

    /**
     * Resolve an organization id to its name.
     *
     * Looked up rather than passed in from the browser: the id is the only thing the
     * merchant actually chose, and a name posted alongside it could be anything. Costs
     * one call at provisioning time and means the panel can say "lr-shopware" instead of
     * a UUID.
     *
     * A failure here is not worth failing the provisioning over - the name is a label.
     */
    private function organizationName(string $organizationId): string
    {
        try {
            foreach ($this->organizations() as $organization) {
                if ($organization['id'] === $organizationId) {
                    return $organization['name'];
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('fastmon: could not resolve the organization name: ' . $e->getMessage());
        }

        return '';
    }
}
