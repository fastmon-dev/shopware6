<?php declare(strict_types=1);

namespace Fastmon\Collector\Dto;

/**
 * The Server-Timing settings in force for one request.
 *
 * @internal resolved by ConfigResolver; nothing else constructs this
 */
final readonly class ServerTimingConfig
{
    /**
     * @param string[] $blockedLayers lower-case layer names kept out of the header
     */
    public function __construct(
        public bool $enabled,
        public bool $reportTotal,
        public bool $reportCacheStatus,
        public bool $reportPageType,
        public bool $reportRender,
        public bool $reportServer,
        /**
         * Off by default. A login flag is a visitor attribute in a header fastmon
         * classifies as server self-measurement and collects in every privacy mode, so
         * turning it on is a decision about that classification, not a display option.
         */
        public bool $reportLoggedIn,
        public array $blockedLayers,
    ) {
    }
}
