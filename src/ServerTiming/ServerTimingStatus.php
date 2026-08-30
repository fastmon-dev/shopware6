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
    /**
     * Every layer the Tideways extension documents, so the panel can also show what
     * exists but did not fire in the sampling request.
     *
     * @var string[]
     */
    public const KNOWN_LAYERS = [
        'amqp', 'apcu', 'autoloading', 'beanstalk', 'compiling', 'disk', 'dns',
        'elasticsearch', 'email', 'gc', 'http', 'kafka', 'memcache', 'mongodb',
        'rdbms', 'redis', 'session', 'shell', 'sleep', 'sqlite', 'unknown',
    ];

    public function __construct(
        private readonly LayerMetricsProviderRegistry $providers,
    ) {
    }

    /**
     * @return array{
     *     available: bool,
     *     source: string,
     *     providers: list<array{name: string, available: bool, state: string, active: bool}>,
     *     extensions: string[],
     *     layers: list<array{name: string, milliseconds: float, recognised: bool}>,
     *     known: list<string>
     * }
     */
    public function describe(): array
    {
        $layers = [];

        foreach ($this->providers->metrics() as $name => $milliseconds) {
            $layers[] = [
                'name' => $name,
                'milliseconds' => round($milliseconds, 1),
                // Whether fastmon promotes this name into a column on its own. The panel
                // uses it to explain why a layer shows up in the dashboard and another
                // one only in the per-beacon drill-down.
                'recognised' => \in_array(
                    mb_strtolower($name),
                    ServerTimingHeaderBuilder::RECOGNISED_LAYERS,
                    true
                ),
            ];
        }

        usort($layers, static fn (array $a, array $b): int => $b['milliseconds'] <=> $a['milliseconds']);

        return [
            'available' => $this->providers->isAvailable(),
            'source' => $this->providers->name(),
            'providers' => $this->providers->describeAll(),
            'extensions' => $this->loadedExtensions(),
            'layers' => $layers,
            'known' => self::KNOWN_LAYERS,
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
