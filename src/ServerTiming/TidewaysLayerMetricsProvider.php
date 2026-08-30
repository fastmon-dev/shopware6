<?php declare(strict_types=1);

namespace Fastmon\Collector\ServerTiming;

/**
 * Reads the layer metrics the Tideways PHP extension collected for this request.
 *
 * `\Tideways\Profiler::getLayerMetrics()` exists from extension 5.23 and returns
 * `\Tideways\Profiler\LayerMetric` objects carrying a `name` and
 * `wallTimeMicroseconds`. Layer names come from the extension: rdbms, redis, http,
 * elasticsearch, memcache, session, disk, dns, email, gc, compiling, autoloading,
 * shell, sleep, unknown and a few more.
 *
 * The class is referenced by string on purpose. A literal `\Tideways\Profiler::` call
 * would be a compile-time reference to a class that exists on no machine without the
 * extension - CI and every developer laptop included - and static analysis would have
 * to be silenced for it. `is_callable()` costs nothing and is true only when the
 * extension is loaded *and* new enough to have the method.
 */
class TidewaysLayerMetricsProvider implements LayerMetricsProviderInterface
{
    private const PROFILER = 'Tideways\\Profiler';
    private const METHOD = 'getLayerMetrics';

    /**
     * The extension reports bare layer names, but the fastmon contract documents a
     * `tideways.layer.` prefix as something it strips - which means some deployments
     * do send it. Stripping here keeps the builder working on one vocabulary.
     */
    private const PREFIX = 'tideways.layer.';

    public function name(): string
    {
        return 'tideways';
    }

    public function isAvailable(): bool
    {
        return \is_callable([self::PROFILER, self::METHOD]);
    }

    public function state(): string
    {
        if ($this->isAvailable()) {
            return 'active';
        }

        // The extension is there but the method is not: `getLayerMetrics()` arrived in
        // 5.23, so this is a version to upgrade rather than software to install - a very
        // different afternoon for whoever reads it.
        return \extension_loaded('tideways') || \extension_loaded('tideways_xhprof')
            ? 'outdated'
            : 'absent';
    }

    public function metrics(): array
    {
        $callable = [self::PROFILER, self::METHOD];

        if (!\is_callable($callable)) {
            return [];
        }

        try {
            /** @var mixed $layers */
            $layers = $callable();
        } catch (\Throwable) {
            // A profiler hiccup must never reach the response.
            return [];
        }

        if (!\is_iterable($layers)) {
            return [];
        }

        $metrics = [];

        foreach ($layers as $layer) {
            if (!\is_object($layer)) {
                continue;
            }

            // Read through get_object_vars() rather than `->` so a future change to the
            // LayerMetric shape degrades to "no metrics" instead of a fatal error.
            $properties = get_object_vars($layer);
            $name = $properties['name'] ?? null;
            $microseconds = $properties['wallTimeMicroseconds'] ?? null;

            if (!\is_string($name) || $name === '' || !\is_numeric($microseconds)) {
                continue;
            }

            if (str_starts_with($name, self::PREFIX)) {
                $name = substr($name, \strlen(self::PREFIX));
            }

            if ($name === '') {
                continue;
            }

            $metrics[$name] = (float) $microseconds / 1000;
        }

        return $metrics;
    }
}
