<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\ServerTiming\ServerTimingHeaderBuilder;
use PHPUnit\Framework\TestCase;

class ServerTimingHeaderBuilderTest extends TestCase
{
    private ServerTimingHeaderBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ServerTimingHeaderBuilder();
    }

    public function testEmitsLayersSlowestFirst(): void
    {
        $header = $this->builder->build(['redis' => 4.0, 'rdbms' => 42.5, 'http' => 12.0]);

        self::assertSame('rdbms;dur=42.5, http;dur=12.0, redis;dur=4.0', $header);
    }

    public function testOurOwnEntriesLeadInTheOrderTheCallerChose(): void
    {
        $header = $this->builder->build(['rdbms' => 42.5], [['fm-backend', 128.44, null]]);

        // fm-backend rather than the Tideways vocabulary: the total is our own
        // measurement, and the alias guarantees the backend_dur column.
        self::assertSame('fm-backend;dur=128.4, rdbms;dur=42.5', $header);
    }

    public function testCacheStatusLeadsEverything(): void
    {
        $header = $this->builder->build(['rdbms' => 42.5], [
            ['fm-fpc', null, 'hit'],
            ['fm-backend', 128.4, null],
        ]);

        self::assertStringStartsWith('fm-fpc;desc=hit, fm-backend;dur=128.4', $header);
    }

    public function testCacheStatusAloneIsAValidHeader(): void
    {
        // The verdict needs no profiler, so a host without one still says something
        // worth reading.
        self::assertSame('fm-fpc;desc=miss', $this->builder->build([], [['fm-fpc', null, 'miss']]));
    }

    public function testRejectsACacheStatusTheCollectorWouldDiscard(): void
    {
        $header = $this->builder->build([], [['fm-fpc', null, str_repeat('x', 33)]]);

        self::assertSame('', $header);
    }

    public function testDropsSubMillisecondLayers(): void
    {
        // The collector discards `dur < 1` without a desc, so sending them only makes
        // the header longer.
        $header = $this->builder->build(['apcu' => 0.4, 'rdbms' => 1.0]);

        self::assertSame('rdbms;dur=1.0', $header);
    }

    public function testKeepsAZeroTotalBecauseFirstPartyKeysAreExemptFromTheFloor(): void
    {
        // A 0.4 ms cache hit is a real measurement and the most interesting one there is.
        self::assertSame('fm-backend;dur=0.4', $this->builder->build([], [['fm-backend', 0.4, null]]));
    }

    public function testTheResidualBucketIsReportedLikeAnyOtherLayer(): void
    {
        // `unknown` is what no instrumented layer claimed - on a Shopware page mostly PHP
        // executing application code. The collector catalogues it, so it costs no custom
        // slot.
        $header = $this->builder->build(['unknown' => 900.0, 'rdbms' => 5.0]);

        self::assertSame('unknown;dur=900.0, rdbms;dur=5.0', $header);
    }

    public function testDropsNamesTheCollectorWouldReject(): void
    {
        $header = $this->builder->build([
            'not a token' => 50.0,
            '9leading' => 50.0,
            str_repeat('a', 33) => 50.0,
            'rdbms' => 5.0,
        ]);

        self::assertSame('rdbms;dur=5.0', $header);
    }

    public function testTheSlowestEntriesAreTheOnesThatSurviveATruncation(): void
    {
        // The collector fills its own caps in the order the header arrives, so the order
        // here is the whole policy: emit slowest first and whatever it drops is the least
        // interesting.
        $metrics = [];

        foreach (range(1, 12) as $i) {
            $metrics['custom' . $i] = (float) $i * 10;
        }

        $names = array_map(
            static fn (string $entry): string => explode(';', $entry)[0],
            explode(', ', $this->builder->build($metrics))
        );

        self::assertSame('custom12', $names[0]);
        self::assertSame('custom1', $names[array_key_last($names)]);
    }

    public function testNeverExceedsTheCollectorsEntryCap(): void
    {
        $metrics = [];

        foreach (range(1, 40) as $i) {
            // Well-formed names are still capped by the total limit.
            $metrics['layer' . $i] = (float) $i * 10;
        }

        $header = $this->builder->build($metrics, [['fm-fpc', null, 'miss'], ['fm-backend', 100.0, null]]);

        self::assertLessThanOrEqual(32, \count(explode(', ', $header)));
    }

    public function testFormatsWithADecimalPointRegardlessOfLocale(): void
    {
        $previous = setlocale(LC_NUMERIC, '0');
        setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'German');

        try {
            self::assertStringContainsString('dur=42.5', $this->builder->build(['rdbms' => 42.5]));
        } finally {
            if (\is_string($previous)) {
                setlocale(LC_NUMERIC, $previous);
            }
        }
    }

    public function testEmptyWhenThereIsNothingToSay(): void
    {
        self::assertSame('', $this->builder->build([]));
    }
}
