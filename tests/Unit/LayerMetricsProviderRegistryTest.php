<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\ServerTiming\LayerMetricsProviderRegistry;
use Fastmon\Collector\Tests\Unit\Fake\FakeLayerMetricsProvider;
use PHPUnit\Framework\TestCase;

class LayerMetricsProviderRegistryTest extends TestCase
{
    public function testTakesTheFirstAvailableProvider(): void
    {
        $registry = new LayerMetricsProviderRegistry([
            new FakeLayerMetricsProvider(false, ['ignored' => 1.0], 'tideways'),
            new FakeLayerMetricsProvider(true, ['rdbms' => 42.0], 'otel'),
        ]);

        self::assertTrue($registry->isAvailable());
        self::assertSame('otel', $registry->name());
        self::assertSame(['rdbms' => 42.0], $registry->metrics());
    }

    public function testUsesOnlyOneSourceEvenWhenSeveralAreAvailable(): void
    {
        // Two profilers measure overlapping things with different boundaries, so summing
        // them would report more database time than the request it sits in.
        $registry = new LayerMetricsProviderRegistry([
            new FakeLayerMetricsProvider(true, ['rdbms' => 10.0], 'tideways'),
            new FakeLayerMetricsProvider(true, ['rdbms' => 99.0], 'otel'),
        ]);

        self::assertSame(['rdbms' => 10.0], $registry->metrics());
        self::assertSame('tideways', $registry->name());
    }

    public function testDegradesQuietlyWhenNothingIsAvailable(): void
    {
        $registry = new LayerMetricsProviderRegistry([new FakeLayerMetricsProvider(false)]);

        self::assertFalse($registry->isAvailable());
        self::assertSame('none', $registry->name());
        self::assertSame([], $registry->metrics());
    }

    public function testDescribesEveryProviderIncludingTheUnavailableOnes(): void
    {
        // The panel has to be able to say "Tideways is installed but too old" rather
        // than only "nothing found".
        $registry = new LayerMetricsProviderRegistry([
            new FakeLayerMetricsProvider(false, [], 'tideways'),
            new FakeLayerMetricsProvider(true, [], 'otel'),
        ]);

        self::assertSame([
            ['name' => 'tideways', 'available' => false, 'state' => 'absent', 'active' => false],
            // The one that actually feeds the header: two sources can be installed and
            // only one is used, deliberately.
            ['name' => 'otel', 'available' => true, 'state' => 'active', 'active' => true],
        ], $registry->describeAll());
    }
}
