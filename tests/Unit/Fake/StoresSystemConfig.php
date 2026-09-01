<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit\Fake;

use Doctrine\DBAL\Connection as Database;
use Fastmon\Collector\Service\ConfigResolver;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * A `SystemConfigService` that is a plain array.
 *
 * Every part of the connection - the credentials, the registration, the authorization in
 * flight - lives in `system_config`, so most of these tests are really about what ends up
 * in that table. Asserting against `$this->stored` says exactly that, and it is the same
 * table on a real shop.
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait StoresSystemConfig
{
    /** @var array<string, mixed> */
    private array $stored = [];

    /**
     * Which keys were written without invalidating the shop's page cache.
     *
     * @var array<string, bool>
     */
    private array $silent = [];

    /**
     * @param bool $memoized emulate `MemoizedSystemConfigStore`, which loads the whole
     *                       configuration once per request and only drops it when this
     *                       process writes. The real one does; a fake that reads live
     *                       hides the bug that behaviour causes.
     */
    private function systemConfig(bool $memoized = false): SystemConfigService
    {
        $snapshot = $memoized ? $this->stored : null;

        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            function (string $key) use (&$snapshot): mixed {
                return $snapshot !== null ? ($snapshot[$key] ?? null) : ($this->stored[$key] ?? null);
            }
        );
        $systemConfig->method('set')->willReturnCallback(
            /** @param string|null $salesChannelId everything this plugin writes is global */
            function (string $key, mixed $value, ?string $salesChannelId = null, bool $silent = false) use (&$snapshot): void {
                unset($salesChannelId);
                $this->stored[$key] = $value;
                $this->silent[$key] = $silent;

                // Writing is what drops the memo, in the process that writes.
                if ($snapshot !== null) {
                    $snapshot = $this->stored;
                }
            }
        );
        $systemConfig->method('delete')->willReturnCallback(
            function (string $key, ?string $salesChannelId = null, bool $silent = false) use (&$snapshot): void {
                unset($salesChannelId, $this->stored[$key]);
                $this->silent[$key] = $silent;

                if ($snapshot !== null) {
                    $snapshot = $this->stored;
                }
            }
        );

        return $systemConfig;
    }

    /**
     * The rows behind `system_config`, as `ConnectionStore::freshCredentials()` reads
     * them: past every cache, `{"_value": …}` and all.
     */
    private function database(): Database
    {
        $database = $this->createMock(Database::class);
        $database->method('fetchAllKeyValue')->willReturnCallback(function (): array {
            $rows = [];

            foreach ($this->stored as $key => $value) {
                if (str_starts_with($key, ConfigResolver::DOMAIN)) {
                    $rows[$key] = json_encode(['_value' => $value], \JSON_THROW_ON_ERROR);
                }
            }

            return $rows;
        });

        return $database;
    }
}
