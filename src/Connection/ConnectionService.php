<?php declare(strict_types=1);

namespace Fastmon\Collector\Connection;

use Fastmon\Collector\Api\FastmonApiException;
use Fastmon\Collector\Api\FastmonClient;
use Fastmon\Collector\Api\FastmonOAuthClient;
use Fastmon\Collector\Api\Pkce;
use Fastmon\Collector\FastmonCollectorException;
use Fastmon\Collector\Service\ConfigResolver;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;

/**
 * Getting the shop a fastmon credential, and saying where it stands.
 *
 * Two ways in, one result. The **app connection** is the good one: this installation
 * registers itself as an OAuth client, the merchant approves it on fastmon's own consent
 * screen - with fastmon's own login and step-up - and the shop ends up holding tokens
 * that expire and rotate. Nothing secret is shipped in the plugin and nothing is typed by
 * hand.
 *
 * Pasting an API key from the dashboard is the fallback for a shop the redirect cannot
 * reach: an administration served over plain http, or an instance without the OAuth
 * endpoints. It is the credential that cannot renew itself, which is precisely why it is
 * second.
 *
 * Everything downstream - provisioning, the storefront snippets, the admin panel - asks
 * `AccessTokenProvider` for a token and never learns which of the two produced it.
 *
 * What the panel *renders* is `ConnectionStatus`. The split is by question: this class
 * changes the connection, that one reports on it.
 */
#[WithMonologChannel('fastmon_collector')]
final class ConnectionService
{
    /**
     * What this integration asks for, and nothing beyond it. The approver may narrow the
     * list on the consent screen and their role narrows it again, so this is a ceiling
     * rather than a promise - which is why the granted scopes are read back and stored.
     *
     * - `org:read` names the organization the connection is bound to, and is what the
     *   panel verifies a live credential with.
     * - `app:read` and `app:write` list, create and configure the application; the
     *   collection mode and the proxy secret are both writes on it.
     * - `site:read` reads the domains fastmon has actually seen, which is the honest
     *   answer to "is it collecting?".
     *
     * No `analytics:read`: the shop never queries measurements, it only produces them.
     */
    public const SCOPES = 'org:read app:read app:write site:read';

    /**
     * How this installation appears in fastmon's connection list. The shop's own host is
     * in it because a merchant with three shops sees three entries that are otherwise
     * identical.
     */
    private const CLIENT_NAME = 'fastmon for Shopware';

    /** fastmon's own limit on `client_name`. */
    private const CLIENT_NAME_MAX = 100;

    public function __construct(
        private readonly FastmonClient $client,
        private readonly FastmonOAuthClient $oauth,
        private readonly ConnectionStore $store,
        private readonly OAuthSession $session,
        private readonly RedirectUri $redirectUri,
        private readonly ConfigResolver $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Send the merchant to fastmon.
     *
     * @param string $adminLocation the administration's own address, as the browser sees it
     *
     * @return array{authorizeUrl: string}
     */
    public function beginAuthorization(string $adminLocation): array
    {
        $redirectUri = $this->redirectUri->resolve($adminLocation);
        $clientId = $this->client($redirectUri);
        $attempt = $this->session->start($clientId, $redirectUri);

        return [
            'authorizeUrl' => $this->oauth->authorizationUrl(
                $this->config->apiBaseUrl(),
                $clientId,
                $redirectUri,
                $attempt['state'],
                // Only the hash travels. The verifier stays in the session on the shop,
                // which is what makes an intercepted code worthless.
                Pkce::challenge($attempt['verifier']),
            ),
        ];
    }

    /**
     * Redeem the code the merchant came back with.
     *
     * @return array{accountEmail: string, accountName: string, organizationId: string, organizationName: string}
     */
    public function completeAuthorization(string $code, string $state): array
    {
        $attempt = $this->session->resolve($state);

        if ($attempt === null) {
            throw new FastmonApiException(
                'This connection attempt is no longer open. Please start again.'
            );
        }

        try {
            $tokens = $this->oauth->exchangeCode(
                $this->config->apiBaseUrl(),
                $attempt->clientId,
                $code,
                $attempt->redirectUri,
                $attempt->verifier,
            );
        } finally {
            // The code is single-use whatever happened to it, so the attempt is over
            // either way - and an attempt left open is one an intercepted code could
            // still be redeemed against.
            $this->session->finish($state);
        }

        $this->store->saveTokens($tokens);
        $this->store->saveAccount($tokens->accountEmail, $tokens->accountName);

        // fastmon settles the organization on its own consent screen, so the shop stores
        // what the merchant already approved instead of asking again. There is no way for
        // it to end up reporting to a different one than they saw.
        if ($tokens->organizationId !== '') {
            $this->store->saveOrganization($tokens->organizationId, $tokens->organizationName);
        }

        $this->logger->info(sprintf(
            'fastmon: connected as %s for organization %s',
            $tokens->accountEmail !== '' ? $tokens->accountEmail : 'an approved account',
            $tokens->organizationName !== '' ? $tokens->organizationName : $tokens->organizationId
        ));

        return [
            'accountEmail' => $tokens->accountEmail,
            'accountName' => $tokens->accountName,
            'organizationId' => $tokens->organizationId,
            'organizationName' => $tokens->organizationName,
        ];
    }

    /**
     * The merchant declined, or fastmon refused before any code existed.
     *
     * @throws FastmonApiException always - this is a failed connect, reported to the panel
     */
    public function declineAuthorization(string $state, string $error): never
    {
        $this->session->finish($state);

        throw new FastmonApiException(match ($error) {
            'access_denied' => 'The connection was declined in fastmon.',
            // Everything else is a protocol-level refusal the merchant cannot act on
            // beyond trying again, so the code is passed through for the log rather than
            // dressed up.
            default => 'fastmon refused the connection: ' . ($error !== '' ? $error : 'unknown error'),
        });
    }

    /**
     * Store an API key the merchant created in the fastmon dashboard.
     *
     * Verified before it is written, so a typo is reported as a typo rather than as a
     * storefront that quietly never provisions. `/organizations` is the check because it
     * is the one call every credential kind can make - `/account` needs a person, and an
     * app connection has none.
     *
     * @return array{organizationId: string, organizationName: string}
     */
    public function connectWithToken(string $token): array
    {
        $token = trim($token);

        if ($token === '') {
            throw FastmonCollectorException::emptyToken();
        }

        $organizations = $this->client->organizations($this->config->apiBaseUrl(), $token);

        $this->store->saveManualToken($token);
        // A key names no person, so a name left over from an app connection would be a
        // lie on the panel.
        $this->store->saveAccount('', '');

        // A key bound to exactly one organization settles the question the same way the
        // consent screen does. More than one, and the merchant picks as before.
        if (\count($organizations) === 1) {
            $this->store->saveOrganization($organizations[0]['id'], $organizations[0]['name']);
        }

        $this->logger->info('fastmon: connected with a pasted API key');

        return [
            'organizationId' => \count($organizations) === 1 ? $organizations[0]['id'] : '',
            'organizationName' => \count($organizations) === 1 ? $organizations[0]['name'] : '',
        ];
    }

    /**
     * End the connection.
     *
     * An app connection is handed back first: presenting the refresh token to
     * `/revoke` ends the grant on fastmon's side, so the shop cannot rotate its way back
     * in and the entry disappears from the merchant's connection list without them having
     * to go and remove it. A failure there is logged and ignored - a shop that cannot
     * reach fastmon still has to be able to disconnect, and the tokens it is about to
     * forget expire on their own within minutes.
     *
     * A pasted key is only forgotten. It lives in the merchant's dashboard and this shop
     * has no authority to revoke it.
     */
    public function disconnect(): void
    {
        $credentials = $this->store->credentials();

        // Any half-finished authorization goes with it, so a late callback cannot revive
        // a connection the merchant just dropped.
        $this->session->abandon();

        if ($credentials->isAppConnection() && $credentials->clientId !== '') {
            try {
                $this->oauth->revoke(
                    $this->config->apiBaseUrl(),
                    $credentials->clientId,
                    $credentials->refreshToken
                );
            } catch (\Throwable $e) {
                $this->logger->warning('fastmon: could not revoke the connection: ' . $e->getMessage());
            }
        }

        $this->store->clear();
        $this->logger->info('fastmon: disconnected');
    }

    /**
     * This installation's OAuth client, registered on first use.
     *
     * Registered again only when the redirect URI changed - a shop that moved domain, or
     * an administration served from somewhere else - because that is the one field a
     * registration cannot be corrected in.
     */
    private function client(string $redirectUri): string
    {
        $credentials = $this->store->credentials();

        if ($credentials->clientId !== '' && $credentials->redirectUri === $redirectUri) {
            return $credentials->clientId;
        }

        $clientId = $this->oauth->register(
            $this->config->apiBaseUrl(),
            $this->clientName($redirectUri),
            $redirectUri,
            self::SCOPES,
        );

        $this->store->saveClient($clientId, $redirectUri);
        $this->logger->info('fastmon: registered this shop as OAuth client ' . $clientId);

        return $clientId;
    }

    private function clientName(string $redirectUri): string
    {
        $host = parse_url($redirectUri, \PHP_URL_HOST);
        $name = \is_string($host) ? self::CLIENT_NAME . ' (' . $host . ')' : self::CLIENT_NAME;

        return mb_substr($name, 0, self::CLIENT_NAME_MAX);
    }

}
