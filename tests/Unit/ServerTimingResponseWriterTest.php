<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\ServerTiming\ServerTimingResponseWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

class ServerTimingResponseWriterTest extends TestCase
{
    private ServerTimingResponseWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new ServerTimingResponseWriter();
    }

    public function testWritesTheValueOnAnUntouchedResponse(): void
    {
        $response = new Response();

        $this->writer->write($response, 'fm-backend;dur=12.0');

        self::assertSame(['fm-backend;dur=12.0'], $response->headers->all('Server-Timing'));
    }

    public function testKeepsEntriesFromOtherSources(): void
    {
        // A CDN, a proxy or another plugin may already have written here, and the spec
        // says the browser concatenates every value.
        $response = new Response();
        $response->headers->set('Server-Timing', 'cfCacheStatus;desc=HIT, cfEdge;dur=15');

        $this->writer->write($response, 'fm-backend;dur=12.0');

        self::assertSame(
            ['cfCacheStatus;desc=HIT, cfEdge;dur=15', 'fm-backend;dur=12.0'],
            $response->headers->all('Server-Timing')
        );
    }

    public function testReplacesOurOwnStaleEntries(): void
    {
        // This is the case that matters: a response served from a full page cache comes
        // back carrying the header it had when it was stored, so the timings belong to a
        // different request entirely.
        $response = new Response();
        $response->headers->set('Server-Timing', 'fm-fpc;desc=miss, fm-backend;dur=250.0, rdbms;dur=200.0');

        $this->writer->write($response, 'fm-fpc;desc=hit, fm-backend;dur=0.8');

        self::assertSame(
            ['rdbms;dur=200.0', 'fm-fpc;desc=hit, fm-backend;dur=0.8'],
            $response->headers->all('Server-Timing')
        );
    }

    public function testIsIdempotent(): void
    {
        $response = new Response();

        $this->writer->write($response, 'fm-backend;dur=12.0');
        $this->writer->write($response, 'fm-backend;dur=13.0');

        self::assertSame(['fm-backend;dur=13.0'], $response->headers->all('Server-Timing'));
    }

    public function testMatchesOurPrefixCaseInsensitively(): void
    {
        $response = new Response();
        $response->headers->set('Server-Timing', 'FM-Backend;dur=250.0');

        $this->writer->write($response, 'fm-backend;dur=1.0');

        self::assertSame(['fm-backend;dur=1.0'], $response->headers->all('Server-Timing'));
    }

    public function testDropsTheEmptyHeaderShopware66Ships(): void
    {
        // Shopware 6.6 registers its profiling listener unconditionally and writes the
        // header with nothing in it. Appending would produce a leading comma, which
        // parses as a nameless metric.
        $response = new Response();
        $response->headers->set('Server-Timing', '');

        $this->writer->write($response, 'fm-backend;dur=12.0');

        self::assertSame(['fm-backend;dur=12.0'], $response->headers->all('Server-Timing'));
    }

    public function testRemovesTheHeaderWhenNothingIsLeft(): void
    {
        $response = new Response();
        $response->headers->set('Server-Timing', 'fm-backend;dur=250.0');

        $this->writer->write($response, '');

        self::assertFalse($response->headers->has('Server-Timing'));
    }
}
