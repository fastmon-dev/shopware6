<?php declare(strict_types=1);

namespace Fastmon\Collector\ServerTiming;

/**
 * What the Server-Timing integration can see on this host, right now.
 *
 * Exists so the admin panel can answer "which layers do I actually get?" with measured
 * data instead of a static list in a help text. The layers it reports are the ones the
 * active provider recorded for the very request that asks - an admin API call - so they
 * are a real sample of this machine rather than a guess.
 */
final class ServerTimingStatus
{
    public function __construct(
        private readonly LayerMetricsProviderRegistry $providers,
    ) {
    }

    /**
     * @return array{
     *     available: bool,
     *     source: string,
     *     providers: list<array{name: string, available: bool, state: string, active: bool}>,
     *     extensions: string[]
     * }
     */
    public function describe(): array
    {
        return [
            'available' => $this->providers->isAvailable(),
            'source' => $this->providers->name(),
            'providers' => $this->providers->describeAll(),
            'extensions' => $this->loadedExtensions(),
        ];
    }

    /**
     * Name and version of every Tideways extension that is loaded, reported even when no
     * provider is available: "tideways 5.12 is loaded" and "nothing is loaded" call for
     * very different fixes, and only the first one is a version bump.
     *
     * @return string[]
     */
    private function loadedExtensions(): array
    {
        $names = array_filter(
            get_loaded_extensions(),
            static fn (string $name): bool => stripos($name, 'tideways') !== false
        );

        $described = [];

        foreach ($names as $name) {
            $version = phpversion($name);
            $described[] = $name . ' ' . (\is_string($version) && $version !== '' ? $version : '?');
        }

        return $described;
    }
}
