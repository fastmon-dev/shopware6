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

    private function systemConfig(): SystemConfigService
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(fn (string $key): mixed => $this->stored[$key] ?? null);
        $systemConfig->method('set')->willReturnCallback(function (string $key, mixed $value): void {
            $this->stored[$key] = $value;
        });
        $systemConfig->method('delete')->willReturnCallback(function (string $key): void {
            unset($this->stored[$key]);
        });

        return $systemConfig;
    }
}
