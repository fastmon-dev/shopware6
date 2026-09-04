<?php declare(strict_types=1);

namespace Fastmon\Collector\ServerTiming;

/**
 * Turns layer wall times into one `Server-Timing` header value that fastmon can read.
 *
 * Format per the W3C Server Timing spec: comma separated `name;dur=<ms>` entries,
 * optionally `;desc=<text>`. Browsers show them in the network panel, and fastmon reads
 * them from `performance.getEntriesByType("navigation")[0].serverTiming`.
 *
 * Two things about the names are decided elsewhere and are worth reading before changing
 * one: the layer names stay as the profiler reports them rather than becoming `fm-*`
 * aliases, and the collector's entry caps are deliberately not arbitrated here. The
 * ordering below (ours first, then layers slowest first) is what the second one relies
 * on.
 *
 * @see docs/server-timing-header.md
 * @see docs/server-timing-setup.md in the fastmon backend for the full contract.
 */
final class ServerTimingHeaderBuilder
{
    /** Total PHP wall time. First-party alias, guaranteed to land in `backend_dur`. */
    public const TOTAL_METRIC = 'fm-backend';

    /**
     * The origin's full page cache, as a pair: the verdict, and on a hit how old the
     * copy it served was. Both carry a `desc`, never a `dur`, and they are emitted one
     * after the other so they arrive that way.
     *
     * `origin` names the tier, the way `origin_cache_status`, `origin_dur` and
     * `origin_host` do on the other side. It is not cosmetic: a shop behind a CDN has
     * two caches and two ages, the edge's and its own, and an unqualified name does not
     * say which one arrived.
     *
     * The age travels as a `desc` because it is in seconds and a `dur` is in
     * milliseconds. As a `dur` it would read as a layer that took 312ms rather than a
     * page that was five minutes old, and sending the milliseconds instead is no way out
     * either: the collector drops any `dur` above ten million as a mistaken timestamp,
     * which is under three hours and well inside what a full page cache serves. As a
     * `desc` it is a number the collector reads as a number and a browser's network panel
     * shows as a label, which is what it is.
     */
    public const ORIGIN_CACHE_METRIC = 'fm-origin-cache';
    public const ORIGIN_AGE_METRIC = 'fm-origin-age';

    /**
     * Entries the collector accepts per pageview. Anything past it is dropped on arrival,
     * so the header is trimmed to fit before it is sent rather than after.
     */
    private const MAX_ENTRIES = 32;

    /**
     * Sub-millisecond entries without a `desc` are discarded by the collector, so
     * emitting them only makes the header longer. The floor is applied here for the
     * same reason the collector applies it: a layer of a few microseconds is not zero,
     * but it does not describe where the request went either.
     */
    private const MIN_DURATION_MS = 1.0;

    /** A metric name the collector will accept, after lower-casing. */
    private const NAME = '/^[a-z][a-z0-9._-]{0,31}$/';

    /** A `desc` the collector will accept. */
    private const DESC = '/^[a-zA-Z0-9 _.:\/-]{1,32}$/';

    /**
     * @param array<string, float>                                     $metrics       layer name => milliseconds
     * @param list<array{0: string, 1: float|null, 2: string|null}>    $own           our own entries as [name, dur, desc]
     */
    public function build(array $metrics, array $own = []): string
    {
        $entries = [];

        // Ours lead, in the order the caller chose. They are exempt from the floor
        // below: a 0.4 ms cache hit is a real and interesting measurement, and the
        // collector exempts `fm-*` keys from its own floor for the same reason.
        foreach ($own as [$name, $duration, $description]) {
            $entry = $this->ownEntry($name, $duration, $description);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        $layers = [];

        foreach ($metrics as $rawName => $milliseconds) {
            $name = mb_strtolower(trim((string) $rawName));

            if ($milliseconds < self::MIN_DURATION_MS) {
                continue;
            }

            if (preg_match(self::NAME, $name) !== 1) {
                continue;
            }

            $layers[$name] = (float) $milliseconds;
        }

        // Slowest first: it is the order a reader wants, and it is also the order the
        // collector reads. Where its own caps bite, they bite from the end, so the
        // entries that survive are the ones worth having.
        arsort($layers);

        foreach ($layers as $name => $milliseconds) {
            if (\count($entries) >= self::MAX_ENTRIES) {
                break;
            }

            $entries[] = $this->entry($name, $milliseconds);
        }

        return implode(', ', $entries);
    }

    /**
     * One of our own entries, or null when it would be dropped on arrival anyway.
     *
     * A `desc` that fails the collector's form check is left out rather than sent and
     * silently discarded - the difference matters when someone is looking at the header
     * wondering why a value never shows up.
     */
    private function ownEntry(string $name, ?float $duration, ?string $description): ?string
    {
        if (preg_match(self::NAME, $name) !== 1) {
            return null;
        }

        if ($description !== null && $description !== '') {
            if (preg_match(self::DESC, $description) !== 1) {
                return null;
            }

            return $duration !== null && $duration >= 0.0
                ? sprintf('%s;dur=%.1F;desc=%s', $name, $duration, $description)
                : sprintf('%s;desc=%s', $name, $description);
        }

        return $duration !== null && $duration >= 0.0 ? $this->entry($name, $duration) : null;
    }

    /**
     * `%.1F` matches the precision the collector rounds to, so the value that arrives is
     * the value that was sent. The capital `F` is the point: `%f` follows `LC_NUMERIC`
     * and writes `42,5` on a German locale, and a decimal comma in the header is an
     * entry the collector drops. Only the float-to-string cast became locale
     * independent in PHP 8.0; `sprintf('%f')` did not.
     */
    private function entry(string $metric, float $milliseconds): string
    {
        return sprintf('%s;dur=%.1F', $metric, $milliseconds);
    }
}
