<?php declare(strict_types=1);

namespace Fastmon\Collector\Dto;

use Fastmon\Collector\Collection\CollectionMode;

/**
 * What the storefront templates need to decide whether and what to inject.
 *
 * `trackerId` and `pixelId` are the application's `source_hash` / `collector_hash`.
 * They are shop-wide rather than per sales channel: one fastmon application covers
 * every domain the shop serves, and the beacon's own hostname is what splits the data
 * into sites on arrival.
 *
 * @internal resolved by ConfigResolver; nothing else constructs this
 */
final readonly class StorefrontConfig
{
    public function __construct(
        public bool $active,
        public string $trackerId,
        public string $pixelId,
        /**
         * Where the browser loads the tracker and the pixel from. Derived from the mode
         * rather than typed in: empty for RELATIVE (same-origin), the merchant's host for
         * CUSTOM, fastmon's for DEFAULT. Keeping it derived is what stops the script and
         * the beacon from ending up on different hosts.
         */
        public string $scriptBaseUrl,
        public bool $errorBootstrap,
        public bool $pixel,
        public CollectionMode $collectionMode,
        public string $customDomain,
    ) {
    }
}
