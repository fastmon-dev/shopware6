<?php declare(strict_types=1);

namespace Fastmon\Collector;

use Doctrine\DBAL\Connection as Database;
use Fastmon\Collector\Connection\ConnectionStore;
use Fastmon\Collector\Connection\OAuthSession;
use LogicException;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class FastmonCollector extends Plugin
{
    /**
     * Drop the stored fastmon credentials on uninstall, unless the merchant asked to keep
     * the plugin's data.
     *
     * Shopware clears a plugin's `system_config` rows itself right after this returns
     * (`PluginLifecycleService::uninstallPlugin()`), so this is belt and braces - but the
     * rows in question authenticate against a live account, and "belt and braces" is the
     * correct amount of care for one of those.
     *
     * It goes through the store rather than naming keys: the store is the one place that
     * knows what it owns, and a second list here would be the one that is forgotten the
     * day a key is added.
     *
     * This does not reach fastmon. An uninstall runs where no HTTP call belongs - it must
     * finish on a shop that cannot reach the internet - so the connection is ended the way
     * it should be ended: with "Disconnect" in the panel, which hands the refresh token
     * back and closes the grant. What is left here after that is an empty row, and what is
     * left after an uninstall without it is a grant the merchant can drop in
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

        // Both are core services no Shopware container is without; the database is only
        // there because the store's constructor wants it for a read this path never
        // makes. Loud rather than a silent return: a container missing either is a
        // broken shop, not a shop with nothing to clean up.
        if (!$systemConfig instanceof SystemConfigService || !$database instanceof Database) {
            throw new LogicException('FastmonCollector: the container offers no SystemConfigService or database connection, so the stored connection could not be removed.');
        }

        (new ConnectionStore($systemConfig, $database))->clearAll();
        (new OAuthSession($systemConfig))->abandon();
    }
}
