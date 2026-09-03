<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Doctrine\DBAL\Connection as Database;
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

        // Everything the store owns - the registration included, which a disconnect
        // keeps - plus any authorization in flight. The count is the guard: a key added
        // to the store must show up here without this test having to know its name.
        self::assertContains(ConfigResolver::DOMAIN . 'oauthRefreshToken', $this->deleted);
        self::assertContains(ConfigResolver::DOMAIN . 'oauthClientId', $this->deleted);
        self::assertContains(ConfigResolver::DOMAIN . 'apiToken', $this->deleted);
        self::assertContains(ConfigResolver::DOMAIN . 'trackerId', $this->deleted);
        self::assertContains(ConfigResolver::DOMAIN . 'oauthSession', $this->deleted);
        self::assertCount(15, $this->deleted);
    }

    public function testAContainerWithoutTheServicesFailsLoudly(): void
    {
        // Rather than returning with the rows still there: a container without
        // SystemConfigService is a broken shop, not a shop with nothing to clean up.
        $plugin = new FastmonCollector(true, \dirname(__DIR__, 2) . '/src');
        $plugin->setContainer(new ContainerBuilder());

        $this->expectException(\LogicException::class);
        $plugin->uninstall($this->context($plugin, keepUserData: false));
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
        $container->set(Database::class, $this->createMock(Database::class));

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
