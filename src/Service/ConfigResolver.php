<?php declare(strict_types=1);

namespace Fastmon\Collector\Service;

use Fastmon\Collector\Collection\CollectionMode;
use Fastmon\Collector\Dto\ServerTimingConfig;
use Fastmon\Collector\Dto\StorefrontConfig;
use Fastmon\Collector\ServerTiming\ServerTimingHeaderBuilder;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Reads the plugin configuration.
 *
 * Does no caching of its own: SystemConfigService already caches, and a copy kept here
 * would outlive an admin change inside a long-running worker.
 *
 * Two scopes are in play and the split is deliberate. Anything describing the fastmon
 * connection - token, organization, application, and the ids it produced - is read
 * **globally** (`null` sales channel), because one application covers the whole shop.
 * Only the switches an operator would plausibly want to differ per storefront are read
 * per sales channel.
 */
final class ConfigResolver
{
    public const DOMAIN = 'FastmonCollector.config.';

    /** Where the tracker bundle and the no-JS pixel are served from. */
    public const DEFAULT_SCRIPT_BASE_URL = 'https://fastmon.site';

    /**
     * The fastmon API. Not a setting: there is one production fastmon, every shop talks
     * to it, and a wrong value here is a connection that fails in a way no merchant can
     * diagnose. Making it configurable bought nothing and offered a footgun.
     *
     * `FASTMON_API_BASE_URL` in the environment overrides it. That exists for developing
     * against a local backend or a stub, which is not something a merchant does - hence
     * an environment variable rather than a field in the administration.
     */
    public const API_BASE_URL = 'https://api.fastmon.eu';

    private const API_BASE_URL_ENV = 'FASTMON_API_BASE_URL';

    /**
     * The fastmon dashboard, which is a different host from the API and cannot be derived
     * from it. Not a setting, for the same reason the API base is not: there is one
     * production fastmon, and a wrong value here is a link that goes nowhere.
     *
     * `FASTMON_APP_BASE_URL` overrides it, for developing against a local dashboard.
     */
    public const APP_BASE_URL = 'https://app.fastmon.eu';

    private const APP_BASE_URL_ENV = 'FASTMON_APP_BASE_URL';

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public function storefront(?string $salesChannelId): StorefrontConfig
    {
        // Shop-wide: the mode mirrors the application's collector mode, and one
        // application covers every sales channel at once.
        $mode = CollectionMode::fromConfigValue($this->systemConfigService->get(self::DOMAIN . 'collectionMode'));
        $customDomain = rtrim($this->string('customCollectorDomain', null), '/');

        return new StorefrontConfig(
            active: $this->bool('active', $salesChannelId, true),
            trackerId: $this->string('trackerId', null),
            pixelId: $this->string('pixelId', null),
            scriptBaseUrl: $this->scriptBaseUrl($mode, $customDomain),
            errorBootstrap: $this->bool('enableErrorBootstrap', $salesChannelId, true),
            pixel: $this->bool('enablePixel', $salesChannelId, true),
            collectionMode: $mode,
            customDomain: $customDomain,
        );
    }

    /**
     * The base the templates prepend to `/s/…` and `/c/…`.
     *
     * Derived, never stored on its own: the mode is the single source of truth, so the
     * script can never end up on a different host than the beacon. A CUSTOM mode with an
     * empty domain falls back to fastmon rather than emitting `/s/…` against the shop -
     * that combination cannot be created through the admin, and rendering a broken
     * same-origin URL would be the worse failure.
     */
    private function scriptBaseUrl(CollectionMode $mode, string $customDomain): string
    {
        return match ($mode) {
            CollectionMode::RELATIVE => '',
            CollectionMode::CUSTOM => $customDomain !== '' ? $customDomain : self::DEFAULT_SCRIPT_BASE_URL,
            CollectionMode::FASTMON => self::DEFAULT_SCRIPT_BASE_URL,
        };
    }

    public function serverTiming(?string $salesChannelId): ServerTimingConfig
    {
        return new ServerTimingConfig(
            enabled: $this->bool('serverTiming', $salesChannelId, true),
            reportTotal: $this->bool('serverTimingTotal', $salesChannelId, true),
            reportCacheStatus: $this->bool('serverTimingCacheStatus', $salesChannelId, true),
            reportPageType: $this->bool('serverTimingPageType', $salesChannelId, true),
            reportRender: $this->bool('serverTimingRender', $salesChannelId, true),
            reportServer: $this->bool('serverTimingServer', $salesChannelId, true),
            reportLoggedIn: $this->bool('serverTimingLoggedIn', $salesChannelId, false),
            blockedLayers: $this->blockedLayers($salesChannelId),
        );
    }

    public function apiBaseUrl(): string
    {
        return $this->baseUrl(self::API_BASE_URL_ENV, self::API_BASE_URL);
    }

    /** Where the merchant reads what this shop is collecting. */
    public function appBaseUrl(): string
    {
        return $this->baseUrl(self::APP_BASE_URL_ENV, self::APP_BASE_URL);
    }

    private function baseUrl(string $variable, string $default): string
    {
        $override = $_SERVER[$variable] ?? getenv($variable);

        return \is_string($override) && trim($override) !== ''
            ? rtrim(trim($override), '/')
            : $default;
    }

    /**
     * Shopware writes the `defaultValue`s from config.xml only on install or activate.
     * A field added by a later release therefore has no stored value at all on a shop
     * that merely pulled new files, and a plain `(bool) null` would silently turn a
     * documented "on by default" into off. Never written means: what config.xml promises.
     */
    private function bool(string $key, ?string $salesChannelId, bool $default): bool
    {
        $value = $this->systemConfigService->get(self::DOMAIN . $key, $salesChannelId);

        return $value === null ? $default : (bool) $value;
    }

    private function string(string $key, ?string $salesChannelId): string
    {
        $value = $this->systemConfigService->get(self::DOMAIN . $key, $salesChannelId);

        return \is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Comma separated in the admin. A value that was never written falls back to the
     * built-in list, so a plugin update can extend the defaults; an explicitly emptied
     * field means the shop wants every layer in the header, `unknown` included.
     *
     * @return string[]
     */
    private function blockedLayers(?string $salesChannelId): array
    {
        $configured = $this->systemConfigService->get(self::DOMAIN . 'blockedServerTimingLayers', $salesChannelId);

        if (!\is_string($configured)) {
            return ServerTimingHeaderBuilder::DEFAULT_BLOCKED_LAYERS;
        }

        $names = array_map(
            static fn (string $name): string => mb_strtolower(trim($name)),
            explode(',', $configured)
        );

        return array_values(array_filter($names, static fn (string $name): bool => $name !== ''));
    }
}
