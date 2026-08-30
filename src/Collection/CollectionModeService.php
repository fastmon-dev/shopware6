<?php declare(strict_types=1);

namespace Fastmon\Collector\Collection;

use Fastmon\Collector\Api\FastmonClient;
use Fastmon\Collector\Connection\ConnectionService;
use Fastmon\Collector\Connection\ConnectionStore;
use Fastmon\Collector\Service\ConfigResolver;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Choosing where the storefront loads the tracker from and where the beacon goes, and
 * refusing a choice that would not work.
 *
 * The order matters and it is the whole point of this class. The collector endpoint lives
 * on the application, server side, and is baked into the bundle fastmon serves. Switching
 * it to a host the merchant has not set up means every tracker in every browser starts
 * posting into a 404 - no error anywhere, no data, and nobody notices until someone opens
 * the dashboard a week later. So a mode that depends on the merchant's own infrastructure
 * is only ever applied after a probe confirmed the paths answer.
 *
 * Going back to fastmon's shared collector needs no proof: it always works, so a merchant
 * whose proxy just broke can undo it immediately.
 *
 * Both sides move together. The application decides where the *beacon* goes; this
 * plugin's template decides where the *script* comes from. If they disagreed, the tracker
 * would load from one host and post to another.
 */
class CollectionModeService
{
    public function __construct(
        private readonly FastmonClient $client,
        private readonly ConnectionService $connection,
        private readonly ConnectionStore $store,
        private readonly ConfigResolver $config,
        private readonly EndpointChecker $checker,
        private readonly SystemConfigService $systemConfigService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{
     *     mode: string, customDomain: string, provisioned: bool, scriptBaseUrl: string,
     *     storefrontOrigins: list<string>,
     *     domains: list<array{domain: string, scriptOk: bool, collectorOk: bool, ready: bool, reason: string, detail: string}>,
     *     ready: bool, checked: bool, checkedMode: string
     * }
     */
    public function describe(?CollectionMode $probeMode = null, string $customDomain = ''): array
    {
        $storefront = $this->config->storefront(null);
        $connection = $this->store->load();

        $base = [
            'mode' => $storefront->collectionMode->value,
            'customDomain' => $storefront->customDomain,
            'provisioned' => $connection->isProvisioned(),
            'scriptBaseUrl' => $storefront->scriptBaseUrl,
            // Shown next to the relative option, so the merchant sees up front how many
            // origins they have to configure rather than after a failed check.
            'storefrontOrigins' => $this->checker->storefrontOrigins(),
            'domains' => [],
            'ready' => false,
            'checked' => false,
            'checkedMode' => '',
        ];

        if ($probeMode === null) {
            return $base;
        }

        $results = $this->checker->check($probeMode, $customDomain);

        return [...$base,
            'domains' => array_map(static fn (DomainCheckResult $r): array => $r->toArray(), $results),
            'ready' => $this->allReady($probeMode, $results),
            'checked' => true,
            'checkedMode' => $probeMode->value,
        ];
    }

    /**
     * The secret the merchant puts into their proxy configuration.
     *
     * Generated on demand rather than at connect time, because it only means anything once
     * someone is actually writing a proxy config - and because generating it again is how
     * rotation works.
     */
    public function generateProxySecret(): string
    {
        $applicationId = $this->requireApplicationId();

        $secret = $this->client->rotateProxySecret(
            $this->config->apiBaseUrl(),
            $this->connection->requireToken(),
            $applicationId
        );

        $this->logger->info('fastmon: proxy secret generated for application ' . $applicationId);

        return $secret;
    }

    /**
     * Apply a collection mode, with proof when the mode needs it.
     *
     * @throws \RuntimeException when the chosen mode's endpoints do not answer
     */
    public function apply(CollectionMode $mode, string $customDomain = ''): void
    {
        $endpoint = '';

        if ($mode === CollectionMode::CUSTOM) {
            $endpoint = $this->checker->normaliseOrigin($customDomain);

            if ($endpoint === '') {
                throw new \RuntimeException('Enter the domain the tracker and beacon should be served from.');
            }
        }

        if ($mode->needsProof()) {
            $results = $this->checker->check($mode, $endpoint);

            if (!$this->allReady($mode, $results)) {
                // The results travel with the refusal so the administration can name the
                // origin and the reason in the reader's language.
                throw new CollectionNotReadyException($results);
            }
        }

        // fastmon first: it owns where the beacon goes, and a failure there must not leave
        // the storefront emitting a script URL for a mode the bundle knows nothing about.
        $this->client->setCollectorMode(
            $this->config->apiBaseUrl(),
            $this->connection->requireToken(),
            $this->requireApplicationId(),
            $mode->value,
            $mode === CollectionMode::CUSTOM ? $endpoint : null,
        );

        $this->systemConfigService->set(ConfigResolver::DOMAIN . 'collectionMode', $mode->value);
        $this->systemConfigService->set(ConfigResolver::DOMAIN . 'customCollectorDomain', $endpoint);

        $this->logger->info('fastmon: collection mode set to ' . $mode->value . ($endpoint !== '' ? ' (' . $endpoint . ')' : ''));
    }

    /**
     * @param list<DomainCheckResult> $results
     */
    private function allReady(CollectionMode $mode, array $results): bool
    {
        if (!$mode->needsProof()) {
            return true;
        }

        // Every origin, not most of them: a single unconfigured storefront would collect
        // nothing while looking exactly like the ones that work. An empty result means
        // nothing could be checked, and nothing checked is not proof.
        return $results !== [] && !array_filter(
            $results,
            static fn (DomainCheckResult $r): bool => !$r->isReady()
        );
    }

    private function requireApplicationId(): string
    {
        $applicationId = $this->store->load()->applicationId;

        if ($applicationId === '') {
            throw new \RuntimeException('No fastmon application is linked to this shop.');
        }

        return $applicationId;
    }
}
