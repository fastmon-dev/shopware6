<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit\Fake;

use Fastmon\Collector\ServerTiming\LayerMetricsProviderInterface;

/**
 * A measurement source with no extension behind it, so the header building can be
 * exercised on any machine.
 */
class FakeLayerMetricsProvider implements LayerMetricsProviderInterface
{
    /**
     * @param array<string, float> $metrics
     */
    public function __construct(
        private readonly bool $available,
        private readonly array $metrics = [],
        private readonly string $name = 'fake',
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function state(): string
    {
        return $this->available ? 'active' : 'absent';
    }

    public function metrics(): array
    {
        return $this->metrics;
    }
}
