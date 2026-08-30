<?php declare(strict_types=1);

namespace Fastmon\Collector;

use Fastmon\Collector\Service\ConfigResolver;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class FastmonCollector extends Plugin
{
    /**
     * Drop the stored fastmon credential on uninstall, unless the merchant asked to keep
     * the plugin's data.
     *
     * Shopware clears a plugin's `system_config` rows itself, so this is belt and braces
     * - but the row in question is an API token that authenticates against a live
     * account, and "belt and braces" is the correct amount of care for one of those.
     *
     * The token stays valid on fastmon's side either way: revoking it is done under
     * "Connected apps" in the fastmon dashboard, and no uninstall here can reach it.
     */
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $systemConfig = $this->container?->get(SystemConfigService::class);

        if (!$systemConfig instanceof SystemConfigService) {
            return;
        }

        $systemConfig->delete(ConfigResolver::DOMAIN . 'apiToken');
    }
}
