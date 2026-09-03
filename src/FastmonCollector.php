<?php declare(strict_types=1);

namespace Fastmon\Collector;

use Doctrine\DBAL\Connection as Database;
use Fastmon\Collector\Connection\ConnectionStore;
use Fastmon\Collector\Connection\Storage\ConnectionDefinition;
use Fastmon\Collector\Service\ConfigResolver;
use LogicException;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class FastmonCollector extends Plugin
{
    /**
     * Remove the plugin's own data on uninstall, unless the merchant asked to keep it.
     *
     * Two things are ours: the connection table, and the two `system_config` values the
     * storefront renders. Shopware clears a plugin's configuration rows itself right
     * after this returns (`PluginLifecycleService::uninstallPlugin()`), so the second
     * part is belt and braces, and a table is nobody's business but ours.
     *
     * ## Only core services here
     *
     * By the time this runs the plugin has been deactivated, and the container it runs in
     * no longer holds anything the plugin defined: asking for the entity's repository
     * ends the uninstall with a `ServiceNotFoundException`, which is a plugin a merchant
     * cannot remove. So the table goes with SQL and the two values through
     * `SystemConfigService`, both of which every Shopware container has. The key names
     * come from `ConnectionStore` rather than being repeated here, because a second list
     * would be the one that is forgotten the day a field is added.
     *
     * ## Nothing reaches fastmon
     *
     * An uninstall runs where no HTTP call belongs, it must finish on a shop that cannot
     * reach the internet, so the connection is ended the way it should be ended: with
     * "Disconnect" in the panel, which hands the refresh token back and closes the grant.
     * What is left after an uninstall without that is a grant the merchant can drop in
     * **Organization settings -> Access**.
     */
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $systemConfig = $this->container?->get(SystemConfigService::class);
        $database = $this->container?->get(Database::class);

        // Loud rather than a silent return: a container without these two is a broken
        // shop, not a shop with nothing to clean up.
        if (!$systemConfig instanceof SystemConfigService || !$database instanceof Database) {
            throw new LogicException('FastmonCollector: the container offers no system configuration or database connection, so the stored connection could not be removed.');
        }

        foreach (ConnectionStore::RENDERED_KEYS as $key) {
            $systemConfig->delete(ConfigResolver::DOMAIN . $key);
        }

        $database->executeStatement('DROP TABLE IF EXISTS `' . ConnectionDefinition::ENTITY_NAME . '`');
    }
}
