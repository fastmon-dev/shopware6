<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\ServerTiming\RequestInsights;
use PHPUnit\Framework\TestCase;

class RequestInsightsTest extends TestCase
{
    public function testTheFirstPageOwnsTheRequest(): void
    {
        // A storefront page renders its own header and footer, and those arrive as
        // separate passes through the kernel. Letting the last one win reported the
        // footer's render time and no page type at all - the bug this guards.
        $insights = new RequestInsights();

        self::assertTrue($insights->beginPage('product', true));
        self::assertFalse($insights->beginPage('home', false), 'a second page must not take over');

        self::assertSame('product', $insights->pageType());
        self::assertTrue($insights->loggedIn());
    }

    public function testTheRenderMeasurementBelongsToThatFirstPage(): void
    {
        $insights = new RequestInsights();
        $insights->beginPage('product', false);
        usleep(2000);
        $insights->endRender();

        $first = $insights->renderMilliseconds();

        self::assertNotNull($first);
        self::assertGreaterThan(0.0, $first);

        // A later pass closing again must not extend or replace it.
        usleep(2000);
        $insights->endRender();

        self::assertSame($first, $insights->renderMilliseconds());
    }

    public function testNothingIsReportedWhenNoPageRendered(): void
    {
        // The cache-hit case: no controller ran, so there is nothing to say. A zero
        // render time would be a real-looking number dragging every average down.
        $insights = new RequestInsights();
        $insights->endRender();

        self::assertNull($insights->pageType());
        self::assertNull($insights->loggedIn());
        self::assertNull($insights->renderMilliseconds());
    }

    public function testResetLetsTheNextRequestStartClean(): void
    {
        // In a long-running process the service outlives the request; without this a page
        // that renders nothing would report the previous one's numbers.
        $insights = new RequestInsights();
        $insights->beginPage('product', true);
        $insights->endRender();

        $insights->reset();

        self::assertNull($insights->pageType());
        self::assertNull($insights->loggedIn());
        self::assertNull($insights->renderMilliseconds());
        self::assertTrue($insights->beginPage('home', false), 'a fresh request must be able to claim it');
    }
}
