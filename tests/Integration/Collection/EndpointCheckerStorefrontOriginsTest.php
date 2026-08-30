<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Integration\Collection;

use Doctrine\DBAL\Connection;
use Fastmon\Collector\Collection\EndpointChecker;
use Fastmon\Collector\Connection\ConnectionStore;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\DevOps\Environment\EnvironmentHelper;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * `storefrontOrigins()` is raw SQL against `sales_channel_domain` and `sales_channel`.
 * The unit test mocks the connection and so never sees the schema; a column renamed in
 * a later Shopware release would first fail in a production shop. This runs the real
 * query, and exercises each of the three filters the docblock promises.
 */
final class EndpointCheckerStorefrontOriginsTest extends TestCase
{
    use IntegrationTestBehaviour;

    private Connection $database;

    private EndpointChecker $checker;

    protected function setUp(): void
    {
        $database = static::getContainer()->get(Connection::class);
        $systemConfig = static::getContainer()->get(SystemConfigService::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(SystemConfigService::class, $systemConfig);

        $this->database = $database;
        $this->checker = new EndpointChecker(new MockHttpClient(), new ConnectionStore($systemConfig), $database);
    }

    public function testTheStorefrontDomainIsAnOrigin(): void
    {
        self::assertContains($this->appOrigin(), $this->checker->storefrontOrigins());
    }

    public function testALanguagePathIsTheSameOrigin(): void
    {
        // Two channels differing only by path are one origin; probing both would report
        // the same answer twice.
        $this->addDomain($this->storefrontChannelId(), $this->appUrl() . '/en');

        $matches = array_filter(
            $this->checker->storefrontOrigins(),
            fn (string $origin): bool => $origin === $this->appOrigin()
        );

        self::assertCount(1, $matches);
    }

    public function testAHeadlessChannelIsLeftOut(): void
    {
        // A headless channel carries a domain too, and no browser ever loads a storefront
        // page there - probing it would fail the switch for the whole shop, forever.
        $this->addDomain($this->apiChannelId(), 'https://headless.example');

        self::assertNotContains('https://headless.example', $this->checker->storefrontOrigins());
    }

    public function testAnInactiveChannelIsLeftOut(): void
    {
        $this->database->executeStatement(
            'UPDATE sales_channel SET active = 0 WHERE id = :id',
            ['id' => Uuid::fromHexToBytes($this->storefrontChannelId())]
        );

        self::assertNotContains($this->appOrigin(), $this->checker->storefrontOrigins());
    }

    private function appUrl(): string
    {
        return (string) EnvironmentHelper::getVariable('APP_URL');
    }

    private function appOrigin(): string
    {
        return $this->checker->normaliseOrigin($this->appUrl());
    }

    private function storefrontChannelId(): string
    {
        $id = $this->database->fetchOne(
            'SELECT LOWER(HEX(sales_channel_id)) FROM sales_channel_domain WHERE url = :url',
            ['url' => $this->appUrl()]
        );

        self::assertIsString($id, 'the test database must have a storefront on APP_URL');

        return $id;
    }

    private function apiChannelId(): string
    {
        $id = $this->database->fetchOne(
            'SELECT LOWER(HEX(id)) FROM sales_channel WHERE type_id = :api LIMIT 1',
            ['api' => Uuid::fromHexToBytes(Defaults::SALES_CHANNEL_TYPE_API)]
        );

        self::assertIsString($id, 'every install ships a headless sales channel');

        return $id;
    }

    /**
     * Inside the test transaction, so it is gone again after the test. Language, currency
     * and snippet set are borrowed from the storefront's own domain: the schema wants
     * valid ids, and which ones makes no difference to the query under test.
     */
    private function addDomain(string $channelId, string $url): void
    {
        $this->database->executeStatement(
            'INSERT INTO sales_channel_domain (id, sales_channel_id, language_id, currency_id, snippet_set_id, url, created_at)
             SELECT :id, :channel, language_id, currency_id, snippet_set_id, :url, NOW()
             FROM sales_channel_domain
             WHERE sales_channel_id = :storefront
             LIMIT 1',
            [
                'id' => Uuid::randomBytes(),
                'channel' => Uuid::fromHexToBytes($channelId),
                'url' => $url,
                'storefront' => Uuid::fromHexToBytes($this->storefrontChannelId()),
            ]
        );
    }
}
