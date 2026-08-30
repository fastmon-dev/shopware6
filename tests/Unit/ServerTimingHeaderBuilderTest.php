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
        $header = $this->builder->build(['redis' => 4.0, 'rdbms' => 42.5, 'http' => 12.0], []);

        self::assertSame('rdbms;dur=42.5, http;dur=12.0, redis;dur=4.0', $header);
    }

    public function testOurOwnEntriesLeadInTheOrderTheCallerChose(): void
    {
        $header = $this->builder->build(['rdbms' => 42.5], [], [['fm-backend', 128.44, null]]);

        // fm-backend rather than the Tideways vocabulary: the total is our own
        // measurement, and the alias guarantees the backend_dur column.
        self::assertSame('fm-backend;dur=128.4, rdbms;dur=42.5', $header);
    }

    public function testCacheStatusLeadsEverything(): void
    {
        $header = $this->builder->build(['rdbms' => 42.5], [], [
            ['fm-fpc', null, 'hit'],
            ['fm-backend', 128.4, null],
        ]);

        self::assertStringStartsWith('fm-fpc;desc=hit, fm-backend;dur=128.4', $header);
    }

    public function testCacheStatusAloneIsAValidHeader(): void
    {
        // The verdict needs no profiler, so a host without one still says something
        // worth reading.
        self::assertSame('fm-fpc;desc=miss', $this->builder->build([], [], [['fm-fpc', null, 'miss']]));
    }

    public function testRejectsACacheStatusTheCollectorWouldDiscard(): void
    {
        $header = $this->builder->build([], [], [['fm-fpc', null, str_repeat('x', 33)]]);

        self::assertSame('', $header);
    }

    public function testDropsSubMillisecondLayers(): void
    {
        // The collector discards `dur < 1` without a desc, so sending them only makes
        // the header longer.
        $header = $this->builder->build(['apcu' => 0.4, 'rdbms' => 1.0], []);

        self::assertSame('rdbms;dur=1.0', $header);
    }

    public function testKeepsAZeroTotalBecauseFirstPartyKeysAreExemptFromTheFloor(): void
    {
        // A 0.4 ms cache hit is a real measurement and the most interesting one there is.
        self::assertSame('fm-backend;dur=0.4', $this->builder->build([], [], [['fm-backend', 0.4, null]]));
    }

    public function testHonoursTheBlocklistCaseInsensitively(): void
    {
        $header = $this->builder->build(['unknown' => 900.0, 'rdbms' => 5.0], ['unknown']);

        self::assertSame('rdbms;dur=5.0', $header);
    }

    public function testDropsNamesTheCollectorWouldReject(): void
    {
        $header = $this->builder->build([
            'not a token' => 50.0,
            '9leading' => 50.0,
            str_repeat('a', 33) => 50.0,
            'rdbms' => 5.0,
        ], []);

        self::assertSame('rdbms;dur=5.0', $header);
    }

    public function testSpendsTheUnrecognisedBudgetOnTheSlowestEntries(): void
    {
        // The collector keeps at most 8 names it does not recognise and drops the rest
        // silently, so the choice of which 8 is made here rather than at random.
        $metrics = ['rdbms' => 5.0];

        foreach (range(1, 12) as $i) {
            $metrics['custom' . $i] = (float) $i * 10;
        }

        $header = $this->builder->build($metrics, []);
        $names = array_map(
            static fn (string $entry): string => explode(';', $entry)[0],
            explode(', ', $header)
        );

        $customs = array_values(array_filter($names, static fn (string $n): bool => str_starts_with($n, 'custom')));

        self::assertCount(8, $customs);
        self::assertSame('custom12', $customs[0], 'the slowest unrecognised layer must survive');
        self::assertNotContains('custom1', $customs, 'the fastest must be the one dropped');
        self::assertContains('rdbms', $names, 'a recognised layer never competes for that budget');
    }

    public function testNeverExceedsTheCollectorsEntryCap(): void
    {
        $metrics = [];

        foreach (range(1, 40) as $i) {
            // All recognised-shaped names would still be capped by the total limit.
            $metrics['layer' . $i] = (float) $i * 10;
        }

        $header = $this->builder->build($metrics, [], [['fm-fpc', null, 'miss'], ['fm-backend', 100.0, null]]);

        self::assertLessThanOrEqual(32, \count(explode(', ', $header)));
    }

    public function testFormatsWithADecimalPointRegardlessOfLocale(): void
    {
        $previous = setlocale(LC_NUMERIC, '0');
        setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'German');

        try {
            self::assertStringContainsString('dur=42.5', $this->builder->build(['rdbms' => 42.5], []));
        } finally {
            if (\is_string($previous)) {
                setlocale(LC_NUMERIC, $previous);
            }
        }
    }

    public function testEmptyWhenThereIsNothingToSay(): void
    {
        self::assertSame('', $this->builder->build([], []));
    }
}
