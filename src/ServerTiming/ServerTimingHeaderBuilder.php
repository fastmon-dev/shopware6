<?php declare(strict_types=1);

namespace Fastmon\Collector\ServerTiming;

/**
 * Turns layer wall times into one `Server-Timing` header value that fastmon can read.
 *
 * Format per the W3C Server Timing spec: comma separated `name;dur=<ms>` entries,
 * optionally `;desc=<text>`. Browsers show them in the network panel, and fastmon reads
 * them from `performance.getEntriesByType("navigation")[0].serverTiming`.
 *
 * ## Why the layer names are mostly left alone
 *
 * fastmon's collector already recognises the Tideways vocabulary and promotes it into
 * the dashboard columns itself (`rdbms` -> db_dur, `redis` -> kv_dur, `elasticsearch`
 * -> search_dur, `http` -> http_dur). Renaming everything to the first-party `fm-*`
 * aliases would gain nothing and would *lose* the per-layer drill-down, because several
 * layers map into the same summed column - two `fm-kv` entries are one number, while
 * `redis` plus `memcache` is that same number and the split that explains it.
 *
 * So `fm-*` is used only where there is no native equivalent to lean on:
 *   - `fm-backend` for total PHP wall time. Our own measurement, and the one entry the
 *     layers below are a share of.
 *   - `fm-fpc` for the full-page-cache verdict, which no profiler reports.
 *   - `fm-host` for the machine that answered. A name, never a duration.
 *
 * And one layer is renamed rather than left alone: `rdbms` goes out as `fm-db`, which is
 * the collector's own first-choice alias for the same column and the word every dashboard
 * and every operator uses for that tier. Nothing is lost, because the relational database
 * is one layer promoted into one column - the drill-down argument above applies to the
 * tiers that have several members, not to this one.
 *
 * ## Why nothing here arbitrates the collector's caps
 *
 * The collector keeps at most 32 entries per pageview and, of those, at most 8 whose
 * names are outside its catalog. Neither limit needs a policy on this side. Every layer
 * Tideways reports is either promoted into a column (`rdbms`, `redis`, `http`, ...) or
 * listed in that catalog (`autoloading`, `compiling`, `gc`, `disk`, ...), so nothing this
 * plugin produces reaches the second limit at all. And where a third-party provider does
 * emit a foreign vocabulary, the collector fills that budget in the order the header
 * arrives, which is the order below: slowest first.
 *
 * @see docs/server-timing-setup.md in the fastmon backend for the full contract.
 */
final class ServerTimingHeaderBuilder
{
    /** Total PHP wall time. First-party alias, guaranteed to land in `backend_dur`. */
    public const TOTAL_METRIC = 'fm-backend';

    /** Full-page-cache verdict. Carries a `desc`, never a `dur`. */
    public const CACHE_METRIC = 'fm-fpc';

    /**
     * `unknown` is Tideways' residual bucket: the request time no instrumented layer
     * claimed, which on a Shopware page is mostly PHP executing application code. It is
     * usually the largest entry, the name says nothing about that, and Tideways' own UI
     * never shows it as a row either. Nothing is lost by hiding it - `fm-backend` minus
     * the entries that follow *is* this number. Clear the setting to get it back.
     *
     * @var string[]
     */
    public const DEFAULT_BLOCKED_LAYERS = ['unknown'];

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
     * @param string[]                                                 $blockedLayers lower-case; empty means report everything
     * @param list<array{0: string, 1: float|null, 2: string|null}>    $own           our own entries as [name, dur, desc]
 */
    public function build(array $metrics, array $blockedLayers, array $own = []): string
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

            if (\in_array($name, $blockedLayers, true)) {
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
                ? sprintf('%s;dur=%.1f;desc=%s', $name, $duration, $description)
                : sprintf('%s;desc=%s', $name, $description);
        }

        return $duration !== null && $duration >= 0.0 ? $this->entry($name, $duration) : null;
    }

    /**
     * `%.1f` matches the precision the collector rounds to, so the value that arrives is
     * the value that was sent. PHP's sprintf has been locale independent for floats
     * since 8.0, so no decimal comma can get into the header.
     */
    private function entry(string $metric, float $milliseconds): string
    {
        return sprintf('%s;dur=%.1f', $metric, $milliseconds);
    }
}
