<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Integration\Connection;

use Doctrine\DBAL\Connection;
use Fastmon\Collector\Api\OAuthTokens;
use Fastmon\Collector\Connection\ConnectionStore;
use Fastmon\Collector\Service\ConfigResolver;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Test\TestDefaults;

/**
 * `freshCredentials()` reads `system_config` with SQL of its own, past the service and
 * its per-request memo. The unit tests drive it through a fake that ignores the
 * statement, so this is where the query, the `sales_channel_id IS NULL` filter and the
 * `{"_value": …}` unwrapping meet the real table.
 */
final class ConnectionStoreTest extends TestCase
{
    use IntegrationTestBehaviour;

    private const REFRESH_TOKEN_KEY = ConfigResolver::DOMAIN . 'oauthRefreshToken';

    private Connection $database;

    private SystemConfigService $systemConfig;

    private ConnectionStore $store;

    protected function setUp(): void
    {
        $database = static::getContainer()->get(Connection::class);
        $systemConfig = static::getContainer()->get(SystemConfigService::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(SystemConfigService::class, $systemConfig);

        $this->database = $database;
        $this->systemConfig = $systemConfig;
        $this->store = new ConnectionStore($systemConfig, $database);
    }

    protected function tearDown(): void
    {
        // The transaction takes the rows back; this takes them out of the memo the
        // shared test kernel would otherwise carry into the next test.
        $this->store->clearAll();
    }

    public function testReadsBackWhatTheServiceWroteUnwrapped(): void
    {
        $this->store->saveClient('dyn_1', 'https://shop.example/admin');
        $this->store->saveTokens($this->tokens('fmt_1', 'fmr_1'));

        $fresh = $this->store->freshCredentials();

        self::assertSame('dyn_1', $fresh->clientId);
        self::assertSame('https://shop.example/admin', $fresh->redirectUri);
        self::assertSame('fmt_1', $fresh->accessToken);
        self::assertSame('fmr_1', $fresh->refreshToken);
        self::assertSame('org:read app:read', $fresh->scopes);
        self::assertGreaterThan(time(), $fresh->expiresAt);
        self::assertSame('', $fresh->manualToken);
        self::assertTrue($fresh->isAppConnection());
    }

    public function testReadsPastThePerRequestMemo(): void
    {
        // The bug that cost a connection in production. A request that waited for the
        // refresh lock had read the configuration before the winner wrote; the service
        // hands it that snapshot, and the snapshot holds a refresh token that is spent.
        $this->store->saveTokens($this->tokens('fmt_1', 'fmr_1'));
        self::assertSame('fmr_1', $this->store->credentials()->refreshToken);

        // Another process rotates the token: the row changes, this process's memo does not.
        $this->database->executeStatement(
            'UPDATE system_config SET configuration_value = :value
             WHERE configuration_key = :key AND sales_channel_id IS NULL',
            ['value' => json_encode(['_value' => 'fmr_2'], \JSON_THROW_ON_ERROR), 'key' => self::REFRESH_TOKEN_KEY]
        );

        self::assertSame('fmr_1', $this->store->credentials()->refreshToken, 'the service memoises per request');
        self::assertSame('fmr_2', $this->store->freshCredentials()->refreshToken);
    }

    public function testAPerChannelRowIsNotTheGlobalCredential(): void
    {
        // The connection is stored globally. A row for a sales channel under the same
        // key is not one this plugin wrote, and must not be read as the credential.
        $this->systemConfig->set(self::REFRESH_TOKEN_KEY, 'not-ours', TestDefaults::SALES_CHANNEL);

        self::assertSame('', $this->store->freshCredentials()->refreshToken);
        self::assertFalse($this->store->freshCredentials()->isAppConnection());
    }

    private function tokens(string $accessToken, string $refreshToken): OAuthTokens
    {
        return new OAuthTokens(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresIn: 900,
            scope: 'org:read app:read',
            accountEmail: 'merchant@example.com',
            accountName: 'Merchant',
            organizationId: 'org-7',
            organizationName: 'Acme',
        );
    }
}
