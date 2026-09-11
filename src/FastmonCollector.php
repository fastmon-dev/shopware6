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
     * after this returns, so the second part is belt and braces; a table is nobody's
     * business but ours. The key names come from `ConnectionStore`, because a second list
     * here would be the one that is forgotten the day a field is added.
     *
     * Core services only. The plugin is deactivated by the time this runs, so the
     * container no longer holds anything it defined: asking for the entity's repository
     * would end the uninstall with a `ServiceNotFoundException`, which is a plugin a
     * merchant cannot remove.
     *
     * Nothing reaches fastmon either, because an uninstall has to finish on a shop with
     * no internet. Ending the grant is what "Disconnect" in the panel is for; after an
     * uninstall without it, the merchant drops the entry under
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
