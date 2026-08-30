<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\ServerTiming\TidewaysLayerMetricsProvider;
use PHPUnit\Framework\TestCase;

class TidewaysLayerMetricsProviderTest extends TestCase
{
    public function testReportsUnavailableWithoutTheExtension(): void
    {
        $provider = new TidewaysLayerMetricsProvider();

        // The suite runs on machines without Tideways, which is the point: the check has
        // to be a runtime one, because a compile-time reference to \Tideways\Profiler
        // would fatal on every one of them.
        if (\is_callable(['Tideways\\Profiler', 'getLayerMetrics'])) {
            self::markTestSkipped('the Tideways extension is loaded on this host');
        }

        self::assertFalse($provider->isAvailable());
        self::assertSame([], $provider->metrics());
        self::assertSame('tideways', $provider->name());
    }
}
