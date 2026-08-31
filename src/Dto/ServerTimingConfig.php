<?php declare(strict_types=1);

namespace Fastmon\Collector\Dto;

/**
 * The Server-Timing setting in force for one request.
 *
 * One switch: every entry is either free (cache verdict, total, node) or measured anyway
 * (render time, page type), so a knob per entry offered a choice nobody has a reason to
 * make - and each was another way for a shop to report less than it thinks.
 *
 * @internal resolved by ConfigResolver; nothing else constructs this
 */
final readonly class ServerTimingConfig
{
    public function __construct(
        public bool $enabled,
    ) {
    }
}
