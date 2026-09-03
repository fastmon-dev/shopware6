<?php declare(strict_types=1);

namespace Fastmon\Collector\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Creates the table the fastmon connection lives in.
 *
 * One row per shop, and every field typed. What used to be a handful of `system_config`
 * keys read through a store built for configuration is a row here: see
 * `ConnectionDefinition` for why that is the difference between a setting and a
 * credential.
 *
 * No data is carried over and none needs to be: this is the release that introduces app
 * connections, so there is no shop with a stored one to migrate.
 */
final class Migration1788415300CreateConnectionTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1788415300;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS `fastmon_collector_connection` (
                `id`                         BINARY(16)   NOT NULL,
                `client_id`                  VARCHAR(255) NULL,
                `redirect_uri`               LONGTEXT     NULL,
                `access_token`               LONGTEXT     NULL,
                `access_token_expires_at`    DATETIME(3)  NULL,
                `refresh_token`              LONGTEXT     NULL,
                `scopes`                     VARCHAR(255) NULL,
                `manual_token`               LONGTEXT     NULL,
                `account_email`              VARCHAR(255) NULL,
                `account_name`               VARCHAR(255) NULL,
                `organization_id`            VARCHAR(255) NULL,
                `organization_name`          VARCHAR(255) NULL,
                `application_id`             VARCHAR(255) NULL,
                `authorization_state`        VARCHAR(64)  NULL,
                `authorization_verifier`     VARCHAR(255) NULL,
                `authorization_client_id`    VARCHAR(255) NULL,
                `authorization_redirect_uri` LONGTEXT     NULL,
                `authorization_expires_at`   DATETIME(3)  NULL,
                `created_at`                 DATETIME(3)  NOT NULL,
                `updated_at`                 DATETIME(3)  NULL,
                PRIMARY KEY (`id`)
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
            SQL
        );
    }

    /**
     * Nothing to do. The table is dropped by the plugin's `uninstall()` when the merchant
     * asks for the data to go, which is the only moment it should be, and there is no
     * column here whose removal a later release has to wait for.
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function updateDestructive(Connection $connection): void
    {
    }
}
