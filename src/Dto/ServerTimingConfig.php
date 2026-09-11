<?php declare(strict_types=1);

namespace Fastmon\Collector\Dto;

/**
 * The Server-Timing settings in force for one request.
 *
 * One switch for the header, and two opt-ins for single entries. Everything else is
 * either free (cache verdict, total) or measured anyway (render time, page type), so a
 * knob per entry offered a choice nobody has a reason to make, and each was another way
 * for a shop to report less than it thinks. The two that stayed are the two that say
 * something about the merchant or the visitor rather than about the request.
 *
 * @internal resolved by ConfigResolver; nothing else constructs this
 */
final readonly class ServerTimingConfig
{
    public function __construct(
        public bool $enabled,
        /** Off by default: only a cluster has a use for the node name. */
        public bool $reportHost,
        /** Off by default: a visitor attribute in a header collected without consent. */
        public bool $reportLoggedIn,
    ) {
    }
}
