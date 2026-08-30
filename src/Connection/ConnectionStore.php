<?php declare(strict_types=1);

namespace Fastmon\Collector\Connection;

use Fastmon\Collector\Service\ConfigResolver;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Reads and writes the fastmon connection in `system_config`.
 *
 * ## On storing the token here
 *
 * `system_config` keeps its values in plain text in the shop database. That is where
 * every Shopware plugin keeps its API credentials - payment providers included - and it
 * is the right place for this one too: encrypting the value would mean keeping a key in
 * `.env`, next to the database credentials that already grant access to the same table.
 * It would change who can read the token from "anyone with the database" to "anyone with
 * the database and the application directory", which is the same person on every shop
 * this plugin will ever run on.
 *
 * The token is declared as a `password` field in config.xml, so the administration masks
 * it, and it is never echoed back by the admin API - `describe()` reports whether one
 * exists, not what it is.
 *
 * Everything is stored globally (`null` sales channel). The per-channel switches live in
 * config.xml and are read by ConfigResolver; nothing here is per channel.
 */
class ConnectionStore
{
    private const TOKEN = 'apiToken';
    private const ACCOUNT_EMAIL = 'accountEmail';
    private const ACCOUNT_NAME = 'accountName';
    private const ORGANIZATION_ID = 'organizationId';
    private const ORGANIZATION_NAME = 'organizationName';
    private const APPLICATION_ID = 'applicationId';
    private const TRACKER_ID = 'trackerId';
    private const PIXEL_ID = 'pixelId';

    /** Everything this store owns, so disconnecting cannot forget a field. */
    private const KEYS = [
        self::TOKEN,
        self::ACCOUNT_EMAIL,
        self::ACCOUNT_NAME,
        self::ORGANIZATION_ID,
        self::ORGANIZATION_NAME,
        self::APPLICATION_ID,
        self::TRACKER_ID,
        self::PIXEL_ID,
    ];

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public function load(): Connection
    {
        return new Connection(
            token: $this->get(self::TOKEN),
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
     * Store a freshly issued token and who it belongs to.
     *
     * Deliberately does not touch the application fields: reconnecting an expired token
     * for the same account must not silently unpublish the storefront snippets.
     */
    public function saveToken(string $token, string $accountEmail, string $accountName): void
    {
        $this->set(self::TOKEN, $token);
        $this->set(self::ACCOUNT_EMAIL, $accountEmail);
        $this->set(self::ACCOUNT_NAME, $accountName);
    }

    /**
     * Record the organization the merchant approved the connection for.
     *
     * Separate from `saveApplication()` because it is known earlier: fastmon carries the
     * consented organization through the grant, so it is settled before there is any
     * application to link. A shop that knows it never asks the merchant to pick one - and
     * more importantly, can never end up reporting to a different organization than the
     * one they saw on the consent screen.
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
     * Forget everything.
     *
     * Local only. The token stays valid on fastmon's side until the merchant removes
     * this integration under "Connected apps" there - the admin module says so, because
     * a merchant who believes "Disconnect" revoked something and finds it did not is
     * worse off than one who was told the truth.
     */
    public function clear(): void
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
