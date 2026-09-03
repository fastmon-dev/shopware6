<?php declare(strict_types=1);

namespace Fastmon\Collector\Migration;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Fastmon\Collector\Service\ConfigResolver;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Drops the rows of the per-entry Server-Timing switches and the layer blocklist.
 *
 * Release 0.1.0 replaced them with the one `serverTiming` switch (see `ServerTimingConfig`
 * for why). Shopware writes `defaultValue`s on install and never removes a row whose field
 * left config.xml, so on an upgraded shop the old rows would sit in `system_config` for
 * good, per sales channel, read by nothing. Deleted with SQL rather than through
 * `SystemConfigService::delete()`, because that deletes one sales channel at a time and
 * these were per-channel switches.
 *
 * `serverTimingLoggedIn` is not on the list: it stayed, as the one opt-in.
 */
final class Migration1788415222DropTimingSwitches extends MigrationStep
{
    /** @var string[] */
    private const REMOVED_KEYS = [
        'serverTimingTotal',
        'serverTimingCacheStatus',
        'serverTimingPageType',
        'serverTimingRender',
        'serverTimingServer',
        'blockedServerTimingLayers',
    ];

    public function getCreationTimestamp(): int
    {
        return 1788415222;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(
            'DELETE FROM system_config WHERE configuration_key IN (:keys)',
            ['keys' => array_map(
                static fn (string $key): string => ConfigResolver::DOMAIN . $key,
                self::REMOVED_KEYS
            )],
            ['keys' => ArrayParameterType::STRING]
        );
    }

    /**
     * Nothing to do: the rows are gone after `update()` and there is no schema to alter.
     * Declared anyway, because 6.6 has it abstract; the parameter is the signature's.
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function updateDestructive(Connection $connection): void
    {
    }
}
