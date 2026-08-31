<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit\Fake;

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
            function (string $key, mixed $value) use (&$snapshot): void {
                $this->stored[$key] = $value;

                // Writing is what drops the memo, in the process that writes.
                if ($snapshot !== null) {
                    $snapshot = $this->stored;
                }
            }
        );
        $systemConfig->method('delete')->willReturnCallback(
            function (string $key) use (&$snapshot): void {
                unset($this->stored[$key]);

                if ($snapshot !== null) {
                    $snapshot = $this->stored;
                }
            }
        );

        // `getDomain()` queries the database itself, so it sees what is stored rather
        // than what this request memoised. That is the whole reason the store uses it.
        $systemConfig->method('getDomain')->willReturnCallback(function (string $domain): array {
            $rows = [];

            foreach ($this->stored as $key => $value) {
                if (str_starts_with($key, $domain)) {
                    $rows[$key] = $value;
                }
            }

            return $rows;
        });

        return $systemConfig;
    }
}
