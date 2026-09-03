<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Doctrine\DBAL\Connection as Database;
use Fastmon\Collector\Connection\Storage\ConnectionDefinition;
use Fastmon\Collector\FastmonCollector;
use Fastmon\Collector\Service\ConfigResolver;
use Fastmon\Collector\Tests\Unit\Fake\StoresConnection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Migration\MigrationCollection;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class FastmonCollectorUninstallTest extends TestCase
{
    use StoresConnection;

    /** @var list<string> */
    private array $statements = [];

    public function testDropsTheWholeConnectionUnlessTheDataIsKept(): void
    {
        $this->config = [
            ConfigResolver::DOMAIN . 'sourceHash' => 'src123',
            ConfigResolver::DOMAIN . 'collectorHash' => 'pix123',
        ];

        $plugin = $this->plugin();
        $plugin->uninstall($this->context($plugin, keepUserData: false));

        // The table goes, and with it every credential in it. The two values the
        // storefront rendered go too: everything the plugin owns, and nothing else.
        self::assertSame([], $this->config);
        self::assertSame(
            ['DROP TABLE IF EXISTS `' . ConnectionDefinition::ENTITY_NAME . '`'],
            $this->statements
        );
    }

    public function testItAsksForNothingThePluginItselfDefines(): void
    {
        // The container an uninstall runs in has already lost the plugin's own services:
        // reaching for the entity's repository here ends the uninstall with a
        // ServiceNotFoundException, which is a plugin the merchant cannot remove. Found
        // by running the integration suite, whose bootstrap uninstalls before it installs.
        $plugin = $this->plugin(withPluginServices: false);

        $plugin->uninstall($this->context($plugin, keepUserData: false));

        self::assertSame(
            ['DROP TABLE IF EXISTS `' . ConnectionDefinition::ENTITY_NAME . '`'],
            $this->statements
        );
    }

    public function testKeepsEverythingWhenAskedTo(): void
    {
        $this->config = [ConfigResolver::DOMAIN . 'sourceHash' => 'src123'];

        $plugin = $this->plugin();
        $plugin->uninstall($this->context($plugin, keepUserData: true));

        self::assertSame([ConfigResolver::DOMAIN . 'sourceHash' => 'src123'], $this->config);
        self::assertSame([], $this->statements);
    }

    public function testAContainerWithoutTheCoreServicesFailsLoudly(): void
    {
        // Rather than returning with the table still there: a container without the
        // system configuration is a broken shop, not a shop with nothing to clean up.
        $plugin = new FastmonCollector(true, \dirname(__DIR__, 2) . '/src');
        $plugin->setContainer(new ContainerBuilder());

        $this->expectException(\LogicException::class);
        $plugin->uninstall($this->context($plugin, keepUserData: false));
    }

    private function plugin(bool $withPluginServices = true): FastmonCollector
    {
        $database = $this->createMock(Database::class);
        $database->method('executeStatement')->willReturnCallback(function (string $sql): int {
            $this->statements[] = $sql;

            return 0;
        });

        $container = new ContainerBuilder();
        $container->set(SystemConfigService::class, $this->systemConfig());
        $container->set(Database::class, $database);

        if ($withPluginServices) {
            $container->set(ConnectionDefinition::ENTITY_NAME . '.repository', $this->connectionRepository());
        }

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
