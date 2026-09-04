<?php declare(strict_types=1);

namespace Fastmon\Collector\ServerTiming;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Source of per-layer wall time for the request being answered right now.
 *
 * Behind an interface for two reasons: the header building stays unit testable on a
 * machine without any profiler extension, and a second APM can feed the same header
 * without the rest of the plugin noticing. `LayerMetricsProviderRegistry` picks the first
 * implementation that reports itself available, so a new source is one class: the tag
 * below is applied to every implementation the container autoconfigures, and autowiring
 * registers it.
 *
 * OpenTelemetry is deliberately not that source. Its spans go to the processors that
 * existed when the TracerProvider was built, during composer autoloading and before any
 * plugin, and there is no API to read them back: the gap is architectural rather than a
 * class nobody has written yet. Tideways is the only source today.
 *
 * The plugin's one extension point, and the one class that is not `final`.
 */
#[AutoconfigureTag('fastmon_collector.layer_metrics_provider')]
interface LayerMetricsProviderInterface
{
    /**
     * Short, stable identifier of the measurement source (`tideways`, `otel`, …).
     * Shown in the admin panel so an operator can tell which one answered.
     */
    public function name(): string;

    /**
     * Cheap enough to call on every response: it must not touch the database and must
     * not allocate, because it is what decides whether any of the rest happens.
     */
    public function isAvailable(): bool;

    /**
     * Why this source is or is not being used, as a code the administration translates.
     *
     * `available` is a yes/no for the registry; this is what an operator needs when the
     * answer is no. "Tideways is installed but too old" and "Tideways is not installed"
     * call for completely different actions, and a boolean cannot tell them apart.
     *
     * @return string `active`, `outdated`, `absent`, or a source-specific reason
     */
    public function state(): string;

    /**
     * Wall time per layer for the current request.
     *
     * Layer names are the source's own vocabulary (Tideways: rdbms, redis, http, …).
     * Translation to the fastmon wire contract happens in ServerTimingHeaderBuilder,
     * so a provider never has to know about it.
     *
     * @return array<string, float> layer name => milliseconds
     */
    public function metrics(): array;
}
