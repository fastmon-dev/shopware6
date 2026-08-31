<?php declare(strict_types=1);

namespace Fastmon\Collector\Storefront;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;

/**
 * Clearing the storefront's page cache, and only when a person asked for it.
 *
 * The plugin changes two things a cached page carries: the tracker id in the snippet and
 * the host the snippet loads from. Until the cache is cleared, visitors keep getting
 * pages built with the old ones, which for a rotated tracker id means they are collected
 * nowhere.
 *
 * Shopware would do this for us: writing a configuration value invalidates the tag every
 * cached page carries, and the cache rebuilds itself. That is exactly what this plugin
 * does not do. On a large shop that invalidation is minutes of rebuilding across the
 * fleet, and it would happen because somebody pressed a button in a plugin configuration
 * screen, at a time nobody chose and with no warning. A merchant who warms their cache
 * after a deployment knows when that is affordable; we do not.
 *
 * So every write this plugin makes is silent, whoever changed something says so in its
 * own answer to the panel, and this is what the button behind that notice calls. The
 * notice lives for one panel session and is gone on the next load: it says what just
 * happened, not what is outstanding, and a merchant who cleared the cache from Settings
 * does not have to come back here to make a banner go away.
 *
 * The tag is core's own: `SystemConfigService::get()` collects `system.config-…` on every
 * page that reads configuration, which is every page this plugin renders into, and core's
 * own invalidation for a changed setting names the same one.
 */
#[WithMonologChannel('fastmon_collector')]
final class StorefrontCache
{
    /**
     * The tag for globally scoped configuration, which is where everything the storefront
     * templates read from this plugin lives.
     */
    private const CONFIG_TAG = 'system.config-';

    public function __construct(
        private readonly CacheInvalidator $invalidator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Drop the cached pages, now rather than on the next queue run: the merchant is
     * standing in front of the panel waiting to see it happen.
     */
    public function clear(): void
    {
        $this->invalidator->invalidate([self::CONFIG_TAG], true);
        $this->logger->info('fastmon: the storefront cache was cleared from the plugin panel');
    }
}
