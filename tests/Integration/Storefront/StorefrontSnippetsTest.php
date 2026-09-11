<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Integration\Storefront;

use Fastmon\Collector\Service\ConfigResolver;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Test\Controller\StorefrontControllerTestBehaviour;

/**
 * What the two template overrides actually render, through the real storefront.
 *
 * The templates gate everything on `sourceHash` and derive the script host from the
 * collection mode. Both rules are asserted in their docblocks; this is where they are
 * proven, on the HTML a browser would get.
 */
final class StorefrontSnippetsTest extends TestCase
{
    use IntegrationTestBehaviour;
    use StorefrontControllerTestBehaviour;

    private const SCRIPT = '#<script defer src="([^"]*)/s/srchash\.js"></script>#';

    private SystemConfigService $config;

    protected function setUp(): void
    {
        $config = static::getContainer()->get(SystemConfigService::class);
        self::assertInstanceOf(SystemConfigService::class, $config);

        $this->config = $config;
    }

    public function testNothingIsRenderedWithoutALinkedApplication(): void
    {
        $html = $this->home();

        self::assertDoesNotMatchRegularExpression('#<script defer src="[^"]*/s/[^"]*\.js">#', $html);
        self::assertDoesNotMatchRegularExpression('#<img src="[^"]*/c/[^"]*\.gif"#', $html);
        self::assertStringNotContainsString('window.__fastmon', $html);
    }

    public function testTheDefaultModeLoadsFromFastmon(): void
    {
        $this->link();

        $html = $this->home();

        self::assertSame('https://fastmon.site', $this->scriptBase($html));
        self::assertStringContainsString('src="https://fastmon.site/c/colhash.gif"', $html);
        // The early-error bootstrap is first in <head>: before any stylesheet, before
        // any other script.
        self::assertStringContainsString('window.__fastmon', $html);
        self::assertLessThan(strpos($html, '<link'), strpos($html, 'window.__fastmon'));
    }

    public function testRelativeModeIsSameOrigin(): void
    {
        $this->link();
        $this->config->set(ConfigResolver::DOMAIN . 'collectionMode', 'relative');

        self::assertSame('', $this->scriptBase($this->home()));
    }

    public function testCustomModeUsesTheMerchantsHost(): void
    {
        $this->link();
        $this->config->set(ConfigResolver::DOMAIN . 'collectionMode', 'custom');
        $this->config->set(ConfigResolver::DOMAIN . 'customCollectorDomain', 'https://metrics.example.com');

        self::assertSame('https://metrics.example.com', $this->scriptBase($this->home()));
    }

    public function testASalesChannelCanOptOut(): void
    {
        $this->link();
        $this->config->set(ConfigResolver::DOMAIN . 'active', false, $this->getSalesChannelId());

        $html = $this->home();

        self::assertDoesNotMatchRegularExpression(self::SCRIPT, $html);
        self::assertStringNotContainsString('window.__fastmon', $html);
    }

    private function link(): void
    {
        $this->config->set(ConfigResolver::DOMAIN . 'sourceHash', 'srchash');
        $this->config->set(ConfigResolver::DOMAIN . 'collectorHash', 'colhash');
    }

    private function scriptBase(string $html): string
    {
        self::assertSame(1, preg_match(self::SCRIPT, $html, $matches), 'the tracker script is rendered');

        return $matches[1] ?? '';
    }

    private function home(): string
    {
        // The HTTP cache is on in the test environment, and the config written above is
        // rolled back with the transaction while the cached page is not.
        $this->clearCacheData();

        $response = $this->request('GET', '', []);

        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getContent();
    }
}
