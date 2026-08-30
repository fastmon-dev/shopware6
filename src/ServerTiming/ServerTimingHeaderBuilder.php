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
 *
 * ## Why the entry budget is enforced here
 *
 * The collector accepts at most 32 entries per pageview and, of those, at most 8 whose
 * names it does not recognise - the rest are dropped silently. Tideways can easily
 * report a dozen layers nobody has a column for (compiling, autoloading, gc, shell,
 * sleep, ...), so left alone the interesting ones would compete with the noise for
 * those 8 slots and lose at random. Sorting slowest first and spending the unrecognised
 * budget deliberately means the entries that survive are the ones worth having.
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
     * Layer names the fastmon collector promotes into a dashboard column or keeps as a
     * documented drill-down key. These never count against the unrecognised budget.
     *
     * Kept in sync with the alias tables in the collector's `_normalize_server_timing()`
     * - a name that drops off that list here only loses its budget exemption, so drift
     * costs precision, never correctness.
     *
     * @var string[]
     */
    public const RECOGNISED_LAYERS = [
        // Promoted into a column.
        'rdbms', 'db', 'sql', 'mongodb', 'sqlite',
        'redis', 'valkey', 'memcache', 'kv', 'cache',
        'elasticsearch', 'opensearch', 'solr', 'search',
        'http', 'fetch', 'api', 'ext',
        'render', 'view', 'ssr',
        'processing', 'total', 'app',
        // Catalogued drill-down keys, including every layer Tideways reports. Missing one
        // here does not lose it, it makes it compete for the eight unrecognised slots the
        // collector would not have applied.
        'amqp', 'apcu', 'auth', 'autoloading', 'beanstalk', 'compiling', 'cpu',
        'db_async', 'disk', 'dns', 'email', 'gc', 'kafka', 'parse', 'queue',
        'session', 'shell', 'sleep', 'theme', 'twig', 'unknown',
    ];

    /**
     * Entries the collector accepts per pageview, and how many of those may carry a name
     * it does not recognise. Anything past either limit is dropped on arrival, so the
     * header is trimmed to fit before it is sent rather than after.
     */
    private const MAX_ENTRIES = 32;
    private const MAX_UNRECOGNISED = 8;

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
 *
 * The entry budget is one policy with several limits; they read as one list here and would not as five methods.
 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
 * @SuppressWarnings("PHPMD.NPathComplexity")
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

        $recognised = [];
        $unrecognised = [];

        foreach ($metrics as $rawName => $milliseconds) {
            $name = mb_strtolower(trim((string) $rawName));

            if ($milliseconds < self::MIN_DURATION_MS) {
                continue;
            }

            if (preg_match(self::NAME, $name) !== 1) {
                continue;
            }

            if (\in_array($name, self::RECOGNISED_LAYERS, true)) {
                $recognised[$name] = (float) $milliseconds;
            } else {
                $unrecognised[$name] = (float) $milliseconds;
            }
        }

        // Slowest first, in both buckets: it is the order a reader wants, and it makes
        // the truncation below drop the least interesting entries rather than arbitrary
        // ones.
        arsort($recognised);
        arsort($unrecognised);

        $unrecognised = \array_slice($unrecognised, 0, self::MAX_UNRECOGNISED, true);

        foreach ([$recognised, $unrecognised] as $bucket) {
            foreach ($bucket as $name => $milliseconds) {
                if (\count($entries) >= self::MAX_ENTRIES) {
                    break 2;
                }

                $entries[] = $this->entry($name, $milliseconds);
            }
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
