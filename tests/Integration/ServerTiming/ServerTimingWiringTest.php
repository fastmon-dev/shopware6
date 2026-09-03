<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Integration\ServerTiming;

use Fastmon\Collector\ServerTiming\RequestInsights;
use Fastmon\Collector\Service\ConfigResolver;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\System\SystemConfig\SystemConfigService;
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

    private const HOST_KEY = ConfigResolver::DOMAIN . 'serverTimingHost';
    private const LOGGED_IN_KEY = ConfigResolver::DOMAIN . 'serverTimingLoggedIn';

    protected function tearDown(): void
    {
        // The transaction rolls the rows back; this drops them from the per-request memo
        // too, which the shared test kernel would otherwise carry into the next test.
        $this->systemConfig()->delete(self::HOST_KEY);
        $this->systemConfig()->delete(self::LOGGED_IN_KEY);
    }

    public function testAStorefrontPageCarriesTheHeader(): void
    {
        $header = $this->homepageHeader();

        self::assertStringContainsString('fm-fpc;desc=miss', $header);
        self::assertMatchesRegularExpression('/fm-backend;dur=[0-9.]+/', $header);
        self::assertStringContainsString('fm-pagetype;desc=home', $header);
        self::assertMatchesRegularExpression('/fm-render;dur=[0-9.]+/', $header);
        // Both off by default: the node name says something about the merchant's
        // infrastructure, the login flag about the visitor. The merchant switches them on.
        self::assertStringNotContainsString('fm-host', $header);
        self::assertStringNotContainsString('fm-loggedin', $header);
        // A miss has no age. Symfony sets `Age` on one anyway, derived from the Date
        // header, so the entry is gated on the hit rather than on the header.
        self::assertStringNotContainsString('fm-cacheage', $header);
    }

    public function testTheOptInsAreReportedOnceSwitchedOn(): void
    {
        $this->systemConfig()->set(self::HOST_KEY, true);
        $this->systemConfig()->set(self::LOGGED_IN_KEY, true);

        $header = $this->homepageHeader();

        // The node name arrives from the injected ServerIdentity, in the form the
        // collector accepts.
        self::assertMatchesRegularExpression('/fm-host;desc=[a-zA-Z0-9 _.:\/-]{1,32}/', $header);
        // Both login values are emitted once it is on, so an absent entry cannot be read
        // as "logged out". A storefront request in the test suite has no customer.
        self::assertStringContainsString('fm-loggedin;desc=no', $header);
    }

    private function homepageHeader(): string
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

        return (string) $response->headers->get('Server-Timing');
    }

    private function systemConfig(): SystemConfigService
    {
        $systemConfig = static::getContainer()->get(SystemConfigService::class);
        self::assertInstanceOf(SystemConfigService::class, $systemConfig);

        return $systemConfig;
    }
}
