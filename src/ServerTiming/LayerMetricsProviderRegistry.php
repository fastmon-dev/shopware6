<?php declare(strict_types=1);

namespace Fastmon\Collector\ServerTiming;

/**
 * Picks the measurement source to use on this host: the first tagged provider that
 * reports itself available, in service-definition order.
 *
 * One source at a time, deliberately. Two profilers measure overlapping things with
 * different boundaries, and summing two views of the same database time would produce a
 * `db` number larger than the request it sits in.
 *
 * The resolution is cached for the lifetime of the request but not beyond: in a
 * long-running worker the answer cannot change (an extension does not get loaded
 * mid-process), and in FPM the object does not outlive the request anyway.
 */
class LayerMetricsProviderRegistry implements LayerMetricsProviderInterface
{
    private ?LayerMetricsProviderInterface $resolved = null;

    private bool $didResolve = false;

    /**
     * @param iterable<LayerMetricsProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
    ) {
    }

    public function name(): string
    {
        return $this->active()?->name() ?? 'none';
    }

    public function state(): string
    {
        return $this->active()?->state() ?? 'absent';
    }

    public function isAvailable(): bool
    {
        return $this->active() !== null;
    }

    public function metrics(): array
    {
        return $this->active()?->metrics() ?? [];
    }

    /**
     * Every provider that is compiled in, available or not. Feeds the admin panel,
     * which has to be able to say "Tideways is installed but too old" rather than
     * only "nothing found".
     *
     * @return list<array{name: string, available: bool, state: string, active: bool}>
     */
    public function describeAll(): array
    {
        $active = $this->active();
        $described = [];

        foreach ($this->providers as $provider) {
            $described[] = [
                'name' => $provider->name(),
                'available' => $provider->isAvailable(),
                'state' => $provider->state(),
                // Which one actually feeds the header. Two sources can be installed and
                // only one is used, deliberately - see the class docblock.
                'active' => $provider === $active,
            ];
        }

        return $described;
    }

    private function active(): ?LayerMetricsProviderInterface
    {
        if ($this->didResolve) {
            return $this->resolved;
        }

        $this->didResolve = true;

        foreach ($this->providers as $provider) {
            if ($provider->isAvailable()) {
                return $this->resolved = $provider;
            }
        }

        return $this->resolved = null;
    }
}
