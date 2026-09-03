<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Doctrine\DBAL\Connection;
use Fastmon\Collector\Migration\Migration1788415222DropTimingSwitches;
use Fastmon\Collector\Service\ConfigResolver;
use PHPUnit\Framework\TestCase;

final class DropTimingSwitchesMigrationTest extends TestCase
{
    public function testDeletesTheRetiredKeysAndNothingElse(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params): int {
                self::assertStringContainsString('DELETE FROM system_config', $sql);
                // No sales channel filter: the switches were per channel, and every row
                // of a key nothing reads any more has to go.
                self::assertStringNotContainsString('sales_channel_id', $sql);

                $keys = $params['keys'];
                self::assertIsArray($keys);

                foreach (['serverTimingTotal', 'serverTimingCacheStatus', 'serverTimingPageType', 'serverTimingRender', 'serverTimingServer', 'blockedServerTimingLayers'] as $retired) {
                    self::assertContains(ConfigResolver::DOMAIN . $retired, $keys);
                }

                // The header switch and the login opt-in are still read.
                self::assertNotContains(ConfigResolver::DOMAIN . 'serverTiming', $keys);
                self::assertNotContains(ConfigResolver::DOMAIN . 'serverTimingLoggedIn', $keys);

                return \count($keys);
            });

        (new Migration1788415222DropTimingSwitches())->update($connection);
    }
}
