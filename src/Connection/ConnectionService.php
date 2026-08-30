<?php declare(strict_types=1);

namespace Fastmon\Collector\Connection;

use Fastmon\Collector\Api\DeviceFlowUnsupportedException;
use Fastmon\Collector\Api\DevicePollStatus;
use Fastmon\Collector\Api\FastmonApiException;
use Fastmon\Collector\Api\FastmonClient;
use Fastmon\Collector\Api\FastmonUnauthorizedException;
use Fastmon\Collector\Service\ConfigResolver;
use Psr\Log\LoggerInterface;

/**
 * Getting the shop a fastmon token, and saying where it stands.
 *
 * Two ways in, one result. The device grant is the good one: the merchant approves the
 * connection in fastmon's own UI, with fastmon's own step-up, and nothing secret is ever
 * shipped or pasted. Pasting a token from the dashboard is the fallback for an instance
 * that does not serve that grant yet.
 *
 * Everything downstream - provisioning, the storefront snippets, the admin panel - sees
 * only "there is a token", so which of the two produced it never has to be recorded and
 * never has to be migrated when the second one goes away.
 */
final class ConnectionService
{
    public function __construct(
        private readonly FastmonClient $client,
        private readonly ConnectionStore $store,
        private readonly DeviceAuthorizationSession $session,
        private readonly ConfigResolver $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * What the admin module renders. Never returns the token itself - only whether one
     * is there - so a stored credential cannot be read back out through the admin API.
     *
     * @return array{
     *     connected: bool, provisioned: bool, accountEmail: string, accountName: string,
     *     organizationId: string, organizationName: string, applicationId: string,
     *     trackerId: string, pixelId: string, apiBaseUrl: string, tokenValid: bool|null,
     *     applicationValid: bool|null, error: string
     * }
     */
    public function describe(bool $verify = false): array
    {
        $connection = $this->store->load();

        $tokenValid = null;
        $applicationValid = null;
        $error = '';

        // Verification costs a round trip to fastmon, so the panel asks for it when it
        // opens and not on every poll.
        if ($verify && $connection->isConnected()) {
            try {
                $this->client->account($this->config->apiBaseUrl(), $connection->token);
                $tokenValid = true;
            } catch (FastmonUnauthorizedException $e) {
                // Revoked in fastmon's "Connected apps", or the account is gone. There is
                // no refresh, so the only way forward is reconnecting.
                $tokenValid = false;
                $error = 'The stored fastmon token was rejected. Please connect again.';
                $this->logger->warning('fastmon: stored token rejected: ' . $e->getMessage());
            } catch (\Throwable $e) {
                // Transient: keep reporting the connection, surface why it could not be
                // checked. Telling a merchant to reconnect because fastmon was briefly
                // unreachable would cost them a working connection.
                $error = $e->getMessage();
                $this->logger->warning('fastmon: could not verify the stored token: ' . $e->getMessage());
            }
        }

        // Self-heal a connection made before the name was resolved at provisioning time,
        // so an existing install shows "lr-shopware" rather than a UUID without anyone
        // having to re-link. Only on verify, and only when it is actually missing.
        $organizationName = $connection->organizationName;

        if ($verify && $tokenValid === true && $connection->organizationId !== '' && $organizationName === '') {
            try {
                foreach ($this->client->organizations($this->config->apiBaseUrl(), $connection->token) as $organization) {
                    if ($organization['id'] === $connection->organizationId) {
                        $organizationName = $organization['name'];
                        $this->store->saveOrganization($connection->organizationId, $organizationName);

                        break;
                    }
                }
            } catch (\Throwable $e) {
                // A label. Never worth failing the status over.
                $this->logger->warning('fastmon: could not resolve the organization name: ' . $e->getMessage());
            }
        }

        // A working token says nothing about the application still being there. It can be
        // deleted in the dashboard, or - as happens when a shop is pointed at a different
        // fastmon instance for testing - refer to an id that never existed on this one.
        // Either way the storefront keeps serving a snippet that collects nothing, and
        // the panel would happily report it as healthy.
        if ($verify && $tokenValid === true && $connection->applicationId !== '') {
            try {
                $application = $this->client->fetchApplication(
                    $this->config->apiBaseUrl(),
                    $connection->token,
                    $connection->applicationId
                );

                // The hashes matter as much as existence: rotating them in the dashboard
                // invalidates the embed everywhere it is deployed, and a shop still
                // serving the old id collects nothing while looking perfectly fine.
                $applicationValid = $application['trackerId'] === $connection->trackerId;

                if (!$applicationValid && $error === '') {
                    $error = 'This application\'s tracker id changed in fastmon. Re-read it to update the storefront.';
                }
            } catch (FastmonApiException $e) {
                $applicationValid = false;

                if ($error === '') {
                    $error = 'The linked fastmon application could not be found. Link an application again.';
                }

                $this->logger->warning('fastmon: linked application not verifiable: ' . $e->getMessage());
            }
        }

        return [
            'connected' => $connection->isConnected(),
            'provisioned' => $connection->isProvisioned(),
            'accountEmail' => $connection->accountEmail,
            'accountName' => $connection->accountName,
            'organizationId' => $connection->organizationId,
            'organizationName' => $organizationName,
            'applicationId' => $connection->applicationId,
            'trackerId' => $connection->trackerId,
            'pixelId' => $connection->pixelId,
            'apiBaseUrl' => $this->config->apiBaseUrl(),
            'tokenValid' => $tokenValid,
            'applicationValid' => $applicationValid,
            'error' => $error,
        ];
    }

    /**
     * Begin a device authorization and return what the merchant needs to see.
     *
     * @return array{handle: string, userCode: string, verificationUri: string, verificationUriComplete: string, expiresIn: int, interval: int}
     *
     * @throws DeviceFlowUnsupportedException when this fastmon instance has no such endpoint
     */
    public function startDeviceAuthorization(): array
    {
        $authorization = $this->client->startDeviceAuthorization($this->config->apiBaseUrl());

        return [
            'handle' => $this->session->start($authorization->deviceCode, $authorization->expiresIn),
            'userCode' => $authorization->userCode,
            'verificationUri' => $authorization->verificationUri,
            'verificationUriComplete' => $authorization->verificationUriComplete,
            'expiresIn' => $authorization->expiresIn,
            'interval' => $authorization->interval,
        ];
    }

    /**
     * Poll once. On approval the token is stored and the handle retired.
     *
     * @return array{status: string, accountEmail: string, accountName: string, organizationId: string}
     */
    public function pollDeviceAuthorization(string $handle): array
    {
        $deviceCode = $this->session->resolve($handle);

        if ($deviceCode === null) {
            // Expired or unknown. Not an error worth an exception: the module simply
            // starts over, which is one click.
            return [
                'status' => 'expired',
                'accountEmail' => '',
                'accountName' => '',
                'organizationId' => '',
            ];
        }

        try {
            $result = $this->client->pollDeviceToken($this->config->apiBaseUrl(), $deviceCode);
        } catch (\Throwable $e) {
            // Declined or expired on fastmon's side: the authorization is over either
            // way, so the handle goes with it rather than being polled forever.
            $this->session->finish($handle);

            throw $e;
        }

        if ($result->status !== DevicePollStatus::COMPLETE) {
            return [
                'status' => $result->status->value,
                'accountEmail' => '',
                'accountName' => '',
                'organizationId' => '',
            ];
        }

        $this->session->finish($handle);
        $this->store->saveToken($result->token, $result->accountEmail, $result->accountName);

        // fastmon settles the organization on its own consent screen, so the shop stores
        // what the merchant already approved instead of asking again. Empty against an
        // instance that does not carry it yet - the module then falls back to asking.
        if ($result->organizationId !== '') {
            $this->store->saveOrganization($result->organizationId, $result->organizationName);
        }

        $this->logger->info('fastmon: connected as ' . $result->accountEmail);

        return [
            'status' => DevicePollStatus::COMPLETE->value,
            'accountEmail' => $result->accountEmail,
            'accountName' => $result->accountName,
            'organizationId' => $result->organizationId,
        ];
    }

    /**
     * Store a token the merchant created in the fastmon dashboard.
     *
     * Verified before it is written, so a typo is reported as a typo rather than as a
     * storefront that quietly never provisions.
     *
     * @return array{accountEmail: string, accountName: string}
     */
    public function connectWithToken(string $token): array
    {
        $token = trim($token);

        if ($token === '') {
            throw new \InvalidArgumentException('No token was given.');
        }

        $account = $this->client->account($this->config->apiBaseUrl(), $token);

        $this->store->saveToken($token, $account['email'], $account['name']);
        $this->logger->info('fastmon: connected as ' . $account['email'] . ' (token)');

        return ['accountEmail' => $account['email'], 'accountName' => $account['name']];
    }

    public function disconnect(): void
    {
        // Any half-finished authorization goes with it, so a later poll cannot revive a
        // connection the merchant just dropped.
        $this->session->abandon();
        $this->store->clear();
        $this->logger->info('fastmon: disconnected (local)');
    }

    /**
     * The token to authenticate an API call with.
     *
     * @throws FastmonUnauthorizedException when the shop has never connected
     */
    public function requireToken(): string
    {
        $connection = $this->store->load();

        if (!$connection->isConnected()) {
            throw new FastmonUnauthorizedException('This shop is not connected to fastmon yet.');
        }

        return $connection->token;
    }
}
