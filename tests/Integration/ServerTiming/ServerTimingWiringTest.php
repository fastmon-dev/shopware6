<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Integration\ServerTiming;

use Fastmon\Collector\ServerTiming\RequestInsights;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Storefront\Test\Controller\StorefrontControllerTestBehaviour;

/**
 * One real storefront request, through the HTTP cache kernel that dispatches
 * `BeforeSendResponseEvent`, and the header it comes back with.
 *
 * The unit tests cover what the subscriber does with what it is given. This covers that
 * it is given anything: that autowiring assembled the chain, that the insight subscriber
 * saw the render, and that the node name arrived from the injected `ServerIdentity`.
 */
final class ServerTimingWiringTest extends TestCase
{
    use IntegrationTestBehaviour;
    use StorefrontControllerTestBehaviour;

    public function testAStorefrontPageCarriesTheHeader(): void
    {
        // The kernel is shared across tests and Shopware's test kernel does not run the
        // services resetter between requests, so what a previous page left behind is
        // cleared by hand - in production `kernel.reset` does this.
        $insights = static::getContainer()->get(RequestInsights::class);
        self::assertInstanceOf(RequestInsights::class, $insights);
        $insights->reset();
        $this->clearCacheData();

        $response = $this->request('GET', '', []);

        self::assertSame(200, $response->getStatusCode());

        $header = (string) $response->headers->get('Server-Timing');

        self::assertStringContainsString('fm-fpc;desc=miss', $header);
        self::assertMatchesRegularExpression('/fm-backend;dur=[0-9.]+/', $header);
        self::assertMatchesRegularExpression('/fm-host;desc=[a-zA-Z0-9 _.:\/-]{1,32}/', $header);
        self::assertStringContainsString('fm-pagetype;desc=home', $header);
        self::assertMatchesRegularExpression('/fm-render;dur=[0-9.]+/', $header);
    }
}
