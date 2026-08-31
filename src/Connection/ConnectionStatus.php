<?php declare(strict_types=1);

namespace Fastmon\Collector\Connection;

use Fastmon\Collector\Api\FastmonApiException;
use Fastmon\Collector\Api\FastmonClient;
use Fastmon\Collector\Api\FastmonCredentialExpiredException;
use Fastmon\Collector\Api\FastmonUnauthorizedException;
use Fastmon\Collector\Service\ConfigResolver;
use Fastmon\Collector\Storefront\StorefrontCache;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;

/**
 * Where the connection stands, as the admin module renders it.
 *
 * Separate from `ConnectionService` because the two answer different questions and change
 * for different reasons. That one gets the shop a credential and gives it back; this one
 * reports on what is stored, and every branch here is one line of the panel: is the
 * credential still good, does the linked application still exist, what may this connection
 * do, and where does the merchant go in fastmon itself.
 *
 * It never returns the credential. The panel learns that one exists and what it may do,
 * never what it is, so a stored token cannot be read back out through the admin API.
 */
#[WithMonologChannel('fastmon_collector')]
final class ConnectionStatus
{
    /** What an unverified connection reports before anything was checked. */
    private const UNCHECKED = [
        'tokenValid' => null,
        'applicationValid' => null,
        'error' => '',
        'organizationName' => null,
    ];

    public function __construct(
        private readonly FastmonClient $client,
        private readonly ConnectionStore $store,
        private readonly AccessTokenProvider $tokens,
        private readonly StorefrontCache $storefrontCache,
        private readonly ConfigResolver $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * What the admin module renders. Never returns the credential itself - only whether
     * one is there and what it may do - so a stored token cannot be read back out through
     * the admin API.
     *
     * @return array{
     *     connected: bool, provisioned: bool, connectionKind: string, scopes: list<string>,
     *     missingScopes: list<string>,
     *     accountEmail: string, accountName: string, organizationId: string,
     *     organizationName: string, applicationId: string, trackerId: string, pixelId: string,
     *     apiBaseUrl: string, dashboardUrl: string, applicationsUrl: string,
     *     tokenValid: bool|null, applicationValid: bool|null, error: string,
     *     cacheStale: bool
     * }
     */
    public function describe(bool $verify = false): array
    {
        $connection = $this->store->load();

        // Verification costs a round trip to fastmon, so the panel asks for it when it
        // opens and not on every reload.
        $checked = $verify && $connection->isConnected() ? $this->verify($connection) : self::UNCHECKED;

        // Verifying can heal what it found - the organization's name, the application's
        // hashes - and the panel has to report what is stored now rather than what was
        // read a round trip ago. It can also drop a credential fastmon has ended, and
        // that one is deliberately not re-read: a panel reporting "not connected" in the
        // same breath as "rejected" would swap the explanation for a blank connect
        // screen, and the merchant would never learn what happened.
        $connection = $checked['tokenValid'] === true ? $this->store->load() : $connection;

        return [
            'connected' => $connection->isConnected(),
            'provisioned' => $connection->isProvisioned(),
            // Which of the two kinds this is. The panel says so, because "disconnect"
            // means something different for each: an app connection is revoked on
            // fastmon's side, a pasted key is only forgotten here.
            'connectionKind' => $this->kind($connection->credentials),
            // What was actually granted. The approver may tick fewer permissions than
            // were asked for, and their role cuts the list again.
            'scopes' => $this->scopeList($connection->credentials),
            // And what is missing of what the plugin needs. Saying it up front beats a
            // permission error on the button that needed it, which is where the merchant
            // would otherwise meet it.
            'missingScopes' => $this->missingScopes($connection->credentials),
            'accountEmail' => $connection->accountEmail,
            'accountName' => $connection->accountName,
            'organizationId' => $connection->organizationId,
            'organizationName' => $checked['organizationName'] ?? $connection->organizationName,
            'applicationId' => $connection->applicationId,
            'trackerId' => $connection->trackerId,
            'pixelId' => $connection->pixelId,
            'apiBaseUrl' => $this->config->apiBaseUrl(),
            // Assembled here rather than in the panel, so the dashboard's routes are
            // written down in one place. Empty without an organization, because both
            // pages live under one and a link to nothing is worse than no link.
            'dashboardUrl' => $this->dashboardUrl($connection, 'dashboard'),
            'applicationsUrl' => $this->dashboardUrl($connection, 'applications'),
            'tokenValid' => $checked['tokenValid'],
            'applicationValid' => $checked['applicationValid'],
            'error' => $checked['error'],
            // The storefront is still serving pages built before the last change here.
            // Nothing clears them on its own, deliberately: the panel says so and offers.
            'cacheStale' => $this->storefrontCache->isStale(),
        ];
    }

    /**
     * Check the stored credential against fastmon, and the application it points at.
     *
     * @return array{tokenValid: bool|null, applicationValid: bool|null, error: string, organizationName: string|null}
     */
    private function verify(Connection $connection): array
    {
        $checked = self::UNCHECKED;

        try {
            // The cheapest call every credential kind can make, and it answers two
            // questions at once: does this still work, and which organization is it for.
            $organizations = $this->tokens->call(
                fn (string $token): array => $this->client->organizations($this->config->apiBaseUrl(), $token)
            );

            $name = $this->organizationName($connection, $organizations);

            if ($name === null) {
                // Not cosmetic: a credential that no longer reaches the linked
                // organization keeps working for everything except this shop's data,
                // which would look like a healthy connection collecting nothing.
                $checked['tokenValid'] = false;
                $checked['error'] = 'This connection no longer reaches the linked fastmon organization. Please connect again.';
            } else {
                $checked['tokenValid'] = true;
                $checked['organizationName'] = $name;
            }
        } catch (FastmonUnauthorizedException | FastmonCredentialExpiredException $e) {
            // Revoked in fastmon, the approver's membership ended, or a refresh token that
            // cannot be renewed any more. All of them end here: reconnect.
            $checked['tokenValid'] = false;
            $checked['error'] = 'The fastmon connection was rejected. Please connect again.';
            $this->logger->warning('fastmon: stored credential rejected: ' . $e->getMessage());
        } catch (\Throwable $e) {
            // Transient: keep reporting the connection, surface why it could not be
            // checked. Telling a merchant to reconnect because fastmon was briefly
            // unreachable would cost them a working connection.
            $checked['error'] = $e->getMessage();
            $this->logger->warning('fastmon: could not verify the stored credential: ' . $e->getMessage());
        }

        return $checked['tokenValid'] === true ? $this->verifyApplication($connection, $checked) : $checked;
    }

    /**
     * A working credential says nothing about the application still being there. It can be
     * deleted in the dashboard, or - as happens when a shop is pointed at a different
     * fastmon instance for testing - refer to an id that never existed on this one. Either
     * way the storefront keeps serving a snippet that collects nothing, and the panel
     * would happily report it as healthy.
     *
     * @param array{tokenValid: bool|null, applicationValid: bool|null, error: string, organizationName: string|null} $checked
     *
     * @return array{tokenValid: bool|null, applicationValid: bool|null, error: string, organizationName: string|null}
     */
    private function verifyApplication(Connection $connection, array $checked): array
    {
        if ($connection->applicationId === '') {
            return $checked;
        }

        try {
            $application = $this->tokens->call(fn (string $token): array => $this->client->fetchApplication(
                $this->config->apiBaseUrl(),
                $token,
                $connection->applicationId
            ));

            // The hashes matter as much as existence: rotating them in the dashboard
            // invalidates the embed everywhere it is deployed, and a shop still serving
            // the old id collects nothing while looking perfectly fine. fastmon owns
            // them, so the shop takes what it is told instead of reporting a
            // disagreement the merchant would have to resolve by hand.
            if ($application['trackerId'] !== '' && $application['trackerId'] !== $connection->trackerId) {
                $this->store->saveApplication(
                    $connection->organizationId,
                    $connection->applicationId,
                    $application['trackerId'],
                    $application['pixelId'],
                );
                $this->logger->info('fastmon: the application hashes changed, the storefront now serves the new ones');
            }

            // What is left to report is existence: an application with no tracker id is
            // one the storefront cannot emit for.
            $checked['applicationValid'] = $application['trackerId'] !== '';
        } catch (FastmonApiException $e) {
            $checked['applicationValid'] = false;

            if ($checked['error'] === '') {
                $checked['error'] = 'The linked fastmon application could not be found. Link an application again.';
            }

            $this->logger->warning('fastmon: linked application not verifiable: ' . $e->getMessage());
        }

        return $checked;
    }

    /**
     * The name of the organization this connection is bound to, or null when the
     * credential does not reach it any more.
     *
     * Doubles as the self-heal for a connection made before the name was resolved: an
     * existing install shows "lr-shopware" rather than a UUID without anyone having to
     * re-link.
     *
     * @param list<array{id: string, name: string}> $organizations
     */
    private function organizationName(Connection $connection, array $organizations): ?string
    {
        if ($connection->organizationId === '') {
            return $connection->organizationName;
        }

        foreach ($organizations as $organization) {
            if ($organization['id'] === $connection->organizationId) {
                return $organization['name'] !== '' ? $organization['name'] : $connection->organizationName;
            }
        }

        return null;
    }

    /**
     * A page in the fastmon dashboard for the organization this shop reports to.
     */
    private function dashboardUrl(Connection $connection, string $page): string
    {
        if ($connection->organizationId === '') {
            return '';
        }

        return sprintf(
            '%s/org/%s/%s',
            $this->config->appBaseUrl(),
            rawurlencode($connection->organizationId),
            $page
        );
    }

    private function kind(Credentials $credentials): string
    {
        if ($credentials->isAppConnection()) {
            return 'app';
        }

        return $credentials->manualToken !== '' ? 'token' : '';
    }

    /**
     * @return list<string>
     */
    private function scopeList(Credentials $credentials): array
    {
        return array_values(array_filter(explode(' ', $credentials->scopes), static fn (string $s): bool => $s !== ''));
    }

    /**
     * What the plugin asked for and did not get.
     *
     * Only for an app connection, and only when fastmon actually reported a scope: a
     * pasted API key carries its permissions on fastmon's side and never tells the shop
     * what they are, so listing all four as missing would be a warning about nothing.
     *
     * @return list<string>
     */
    private function missingScopes(Credentials $credentials): array
    {
        if (!$credentials->isAppConnection() || $credentials->scopes === '') {
            return [];
        }

        return array_values(array_diff(explode(' ', ConnectionService::SCOPES), $this->scopeList($credentials)));
    }
}
