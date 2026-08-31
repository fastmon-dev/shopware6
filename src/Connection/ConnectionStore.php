<?php declare(strict_types=1);

namespace Fastmon\Collector\Connection;

use Fastmon\Collector\Api\OAuthTokens;
use Fastmon\Collector\Service\ConfigResolver;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Reads and writes the fastmon connection in `system_config`.
 *
 * ## On storing tokens here
 *
 * `system_config` keeps its values in plain text in the shop database. That is where
 * every Shopware plugin keeps its API credentials - payment providers included - and it
 * is the right place for these too: encrypting the value would mean keeping a key in
 * `.env`, next to the database credentials that already grant access to the same table.
 * It would change who can read the token from "anyone with the database" to "anyone with
 * the database and the application directory", which is the same person on every shop
 * this plugin will ever run on.
 *
 * What did change with app connections is how much a stolen row is worth. The access
 * token expires in minutes, and the refresh token rotates on every use - so a copy taken
 * from a backup stops working the moment the shop refreshes, and using it announces the
 * theft, because fastmon ends a connection whose refresh token is presented twice.
 *
 * None of it is a form field. There is no `password` input in config.xml to mask, because
 * nothing here is meant to be typed or read by a person - the panel writes it through the
 * plugin's own admin API, and that API reports whether a connection exists and what it may
 * do, never what it is.
 *
 * Everything is stored globally (`null` sales channel). The per-channel switches live in
 * config.xml and are read by ConfigResolver; nothing here is per channel.
 *
 * One method per group of fields that is written together, and that is the design: the
 * order inside `saveTokens()` is load-bearing, and a caller assembling the writes itself
 * is exactly the coupling this class exists to remove.
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
final class ConnectionStore
{
    /**
     * The registration, which is not a credential.
     *
     * Kept across a disconnect on purpose: a `client_id` is public by design, it is how
     * this shop appears in fastmon's connection list, and registering again on every
     * reconnect would leave a trail of clients nobody can tell apart - and would run into
     * the registration rate limit on a shop that reconnects a few times in a row.
     */
    private const CLIENT_ID = 'oauthClientId';
    private const REDIRECT_URI = 'oauthRedirectUri';

    private const ACCESS_TOKEN = 'oauthAccessToken';
    private const EXPIRES_AT = 'oauthExpiresAt';
    private const REFRESH_TOKEN = 'oauthRefreshToken';
    private const SCOPES = 'oauthScopes';

    /**
     * The pasted-key fallback. Keeps its original name: a shop that connected this way
     * before app connections existed must keep working across the update.
     */
    private const MANUAL_TOKEN = 'apiToken';

    private const ACCOUNT_EMAIL = 'accountEmail';
    private const ACCOUNT_NAME = 'accountName';
    private const ORGANIZATION_ID = 'organizationId';
    private const ORGANIZATION_NAME = 'organizationName';
    private const APPLICATION_ID = 'applicationId';
    private const TRACKER_ID = 'trackerId';
    private const PIXEL_ID = 'pixelId';

    /** Everything that authenticates. Dropped together, whichever kind is stored. */
    private const CREDENTIAL_KEYS = [
        self::ACCESS_TOKEN,
        self::EXPIRES_AT,
        self::REFRESH_TOKEN,
        self::SCOPES,
        self::MANUAL_TOKEN,
    ];

    /** Who and what the credential pointed at. */
    private const IDENTITY_KEYS = [
        self::ACCOUNT_EMAIL,
        self::ACCOUNT_NAME,
        self::ORGANIZATION_ID,
        self::ORGANIZATION_NAME,
        self::APPLICATION_ID,
        self::TRACKER_ID,
        self::PIXEL_ID,
    ];

    /** Everything this store owns, so an uninstall cannot forget a field. */
    private const KEYS = [
        self::CLIENT_ID,
        self::REDIRECT_URI,
        ...self::CREDENTIAL_KEYS,
        ...self::IDENTITY_KEYS,
    ];

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public function load(): Connection
    {
        return new Connection(
            credentials: $this->credentials(),
            accountEmail: $this->get(self::ACCOUNT_EMAIL),
            accountName: $this->get(self::ACCOUNT_NAME),
            organizationId: $this->get(self::ORGANIZATION_ID),
            organizationName: $this->get(self::ORGANIZATION_NAME),
            applicationId: $this->get(self::APPLICATION_ID),
            trackerId: $this->get(self::TRACKER_ID),
            pixelId: $this->get(self::PIXEL_ID),
        );
    }

    /**
     * Just the credential, for the hot path: every API call reads this and almost none of
     * them care who approved the connection.
     */
    public function credentials(): Credentials
    {
        return new Credentials(
            clientId: $this->get(self::CLIENT_ID),
            redirectUri: $this->get(self::REDIRECT_URI),
            accessToken: $this->get(self::ACCESS_TOKEN),
            expiresAt: (int) $this->get(self::EXPIRES_AT),
            refreshToken: $this->get(self::REFRESH_TOKEN),
            scopes: $this->get(self::SCOPES),
            manualToken: $this->get(self::MANUAL_TOKEN),
        );
    }

    /**
     * The credential as the database has it **right now**.
     *
     * `get()` reads through `MemoizedSystemConfigStore`, which loads the whole
     * configuration once per request and drops it only when *this* process writes. That
     * is right for configuration and wrong for exactly one value. A second admin API call
     * that waits for the refresh lock started its request before the winner wrote, so
     * reading through `get()` hands it back its own snapshot: the refresh token it was
     * about to present, which the winner has already spent. Presenting a spent one is
     * what fastmon reads as theft, and it ends the connection.
     *
     * `getDomain()` is the way out and needs no SQL of ours: it builds its own query
     * against `system_config`, so it never sees the memo, and it unwraps the stored
     * values the same way `get()` does.
     */
    public function freshCredentials(): Credentials
    {
        $stored = [];

        foreach ($this->systemConfigService->getDomain(ConfigResolver::DOMAIN) as $key => $value) {
            $stored[str_replace(ConfigResolver::DOMAIN, '', (string) $key)] = \is_scalar($value)
                ? trim((string) $value)
                : '';
        }

        return new Credentials(
            clientId: $stored[self::CLIENT_ID] ?? '',
            redirectUri: $stored[self::REDIRECT_URI] ?? '',
            accessToken: $stored[self::ACCESS_TOKEN] ?? '',
            expiresAt: (int) ($stored[self::EXPIRES_AT] ?? '0'),
            refreshToken: $stored[self::REFRESH_TOKEN] ?? '',
            scopes: $stored[self::SCOPES] ?? '',
            manualToken: $stored[self::MANUAL_TOKEN] ?? '',
        );
    }

    /** Remember the registration, and the redirect URI it is only valid for. */
    public function saveClient(string $clientId, string $redirectUri): void
    {
        $this->set(self::CLIENT_ID, $clientId);
        $this->set(self::REDIRECT_URI, $redirectUri);
    }

    /**
     * Store a freshly issued pair.
     *
     * **The refresh token is written first, and that order is the whole point.** Each one
     * works exactly once: if the process died between the two writes, losing the
     * successor would leave the shop holding a spent token, and presenting a spent token
     * is what fastmon reads as theft - it would end the connection. Written in this
     * order, the worst case is an access token the shop forgot it had, which the next
     * refresh replaces.
     *
     * Any pasted key goes at the same time: an app connection supersedes it, and leaving
     * one behind would mean a fallback quietly taking over the moment the grant ends.
     */
    public function saveTokens(OAuthTokens $tokens): void
    {
        $this->set(self::REFRESH_TOKEN, $tokens->refreshToken);
        $this->set(self::SCOPES, $tokens->scope);
        $this->set(self::EXPIRES_AT, (string) (time() + $tokens->expiresIn));
        $this->set(self::ACCESS_TOKEN, $tokens->accessToken);
        $this->systemConfigService->delete(ConfigResolver::DOMAIN . self::MANUAL_TOKEN);
    }

    /**
     * Store a key the merchant created in the fastmon dashboard.
     *
     * Replaces an app connection rather than sitting beside it, so there is never a
     * question of which of two credentials a call was made with.
     */
    public function saveManualToken(string $token): void
    {
        foreach (self::CREDENTIAL_KEYS as $key) {
            $this->systemConfigService->delete(ConfigResolver::DOMAIN . $key);
        }

        $this->set(self::MANUAL_TOKEN, $token);
    }

    /** Who approved the connection. fastmon reports this once, at consent. */
    public function saveAccount(string $accountEmail, string $accountName): void
    {
        $this->set(self::ACCOUNT_EMAIL, $accountEmail);
        $this->set(self::ACCOUNT_NAME, $accountName);
    }

    /**
     * Record the organization the connection is bound to.
     *
     * Separate from `saveApplication()` because it is known earlier and from a better
     * source: the token response names the organization the merchant approved on the
     * consent screen, so the shop can never end up reporting to a different one than the
     * one they saw.
     */
    public function saveOrganization(string $organizationId, string $organizationName): void
    {
        $this->set(self::ORGANIZATION_ID, $organizationId);
        $this->set(self::ORGANIZATION_NAME, $organizationName);
    }

    /**
     * Point the storefront at an application. `trackerId` is what actually turns the
     * snippets on, so it is written last - a half-written link renders nothing rather
     * than a script tag with an empty id.
     */
    public function saveApplication(string $organizationId, string $applicationId, string $trackerId, string $pixelId): void
    {
        $this->set(self::ORGANIZATION_ID, $organizationId);
        $this->set(self::APPLICATION_ID, $applicationId);
        $this->set(self::PIXEL_ID, $pixelId);
        $this->set(self::TRACKER_ID, $trackerId);
    }

    /**
     * Drop the credential and leave everything else standing.
     *
     * What a rejected connection needs: the linked application, its hashes and the
     * organization are still correct, and reconnecting for the same organization must not
     * cost the merchant the storefront snippets.
     */
    public function clearCredentials(): void
    {
        foreach (self::CREDENTIAL_KEYS as $key) {
            $this->systemConfigService->delete(ConfigResolver::DOMAIN . $key);
        }
    }

    /**
     * Disconnect: the credential and everything it pointed at.
     *
     * The registration stays, because it is not a credential and re-using it is what
     * keeps this shop one entry in fastmon's connection list rather than a new one per
     * reconnect.
     */
    public function clear(): void
    {
        $this->clearCredentials();

        foreach (self::IDENTITY_KEYS as $key) {
            $this->systemConfigService->delete(ConfigResolver::DOMAIN . $key);
        }
    }

    /** Uninstall: everything this store owns, registration included. */
    public function clearAll(): void
    {
        foreach (self::KEYS as $key) {
            $this->systemConfigService->delete(ConfigResolver::DOMAIN . $key);
        }
    }

    private function get(string $key): string
    {
        $value = $this->systemConfigService->get(ConfigResolver::DOMAIN . $key);

        return \is_scalar($value) ? trim((string) $value) : '';
    }

    private function set(string $key, string $value): void
    {
        $this->systemConfigService->set(ConfigResolver::DOMAIN . $key, $value);
    }
}
