<?php declare(strict_types=1);

namespace Fastmon\Collector\Dto;

/**
 * The Server-Timing settings in force for one request.
 *
 * @internal resolved by ConfigResolver; nothing else constructs this
 */
final class ServerTimingConfig
{
    /**
     * @param string[] $blockedLayers lower-case layer names kept out of the header
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly bool $reportTotal,
        public readonly bool $reportCacheStatus,
        public readonly bool $reportPageType,
        public readonly bool $reportRender,
        public readonly bool $reportServer,
        /**
         * Off by default. A login flag is a visitor attribute in a header fastmon
         * classifies as server self-measurement and collects in every privacy mode, so
         * turning it on is a decision about that classification, not a display option.
         */
        public readonly bool $reportLoggedIn,
        public readonly array $blockedLayers,
    ) {
    }
}
