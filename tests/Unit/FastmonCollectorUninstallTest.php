<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\FastmonCollector;
use Fastmon\Collector\Service\ConfigResolver;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Migration\MigrationCollection;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class FastmonCollectorUninstallTest extends TestCase
{
    /** @var list<string> */
    private array $deleted = [];

    public function testDropsTheWholeConnectionUnlessTheDataIsKept(): void
    {
        $plugin = $this->plugin();

        $plugin->uninstall($this->context($plugin, keepUserData: false));

        // Everything the store owns plus any in-flight device authorization. The count
        // is the guard: a key added to the store must show up here without this test
        // having to know its name.
        self::assertContains(ConfigResolver::DOMAIN . 'apiToken', $this->deleted);
        self::assertContains(ConfigResolver::DOMAIN . 'trackerId', $this->deleted);
        self::assertContains(ConfigResolver::DOMAIN . 'deviceAuthorization', $this->deleted);
        self::assertCount(9, $this->deleted);
    }

    public function testKeepsEverythingWhenAskedTo(): void
    {
        $plugin = $this->plugin();

        $plugin->uninstall($this->context($plugin, keepUserData: true));

        self::assertSame([], $this->deleted);
    }

    private function plugin(): FastmonCollector
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('delete')->willReturnCallback(function (string $key): void {
            $this->deleted[] = $key;
        });

        $container = new ContainerBuilder();
        $container->set(SystemConfigService::class, $systemConfig);

        $plugin = new FastmonCollector(true, \dirname(__DIR__, 2) . '/src');
        $plugin->setContainer($container);

        return $plugin;
    }

    private function context(FastmonCollector $plugin, bool $keepUserData): UninstallContext
    {
        return new UninstallContext(
            $plugin,
            Context::createDefaultContext(),
            '6.7.0.0',
            '0.1.0',
            $this->createMock(MigrationCollection::class),
            $keepUserData,
        );
    }
}
