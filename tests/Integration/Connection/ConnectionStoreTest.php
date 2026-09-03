<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Integration\Connection;

use Fastmon\Collector\Api\OAuthTokens;
use Fastmon\Collector\Connection\Authorization;
use Fastmon\Collector\Connection\ConnectionStore;
use Fastmon\Collector\Connection\Storage\ConnectionCollection;
use Fastmon\Collector\Connection\Storage\ConnectionDefinition;
use Fastmon\Collector\Service\ConfigResolver;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The connection against its own table, through the real DAL.
 *
 * The unit tests drive the store through a repository that is an array, so this is where
 * the definition, the migration and the entity meet: that every field survives a write
 * and a read, that a write by another process is visible at once (which the refresh path
 * depends on), and that nothing here can be read through the API.
 */
final class ConnectionStoreTest extends TestCase
{
    use IntegrationTestBehaviour;

    private ConnectionStore $store;

    /** @var EntityRepository<ConnectionCollection> */
    private EntityRepository $repository;

    private SystemConfigService $systemConfig;

    protected function setUp(): void
    {
        $repository = static::getContainer()->get(ConnectionDefinition::ENTITY_NAME . '.repository');
        $systemConfig = static::getContainer()->get(SystemConfigService::class);
        self::assertInstanceOf(EntityRepository::class, $repository);
        self::assertInstanceOf(SystemConfigService::class, $systemConfig);

        /** @var EntityRepository<ConnectionCollection> $repository */
        $this->repository = $repository;
        $this->systemConfig = $systemConfig;
        $this->store = new ConnectionStore($repository, $systemConfig);
    }

    protected function tearDown(): void
    {
        // The transaction takes the row back; this takes the two configuration values
        // out of the memo the shared test kernel would otherwise carry into the next test.
        $this->store->clearAll();
    }

    public function testEveryFieldSurvivesAWriteAndARead(): void
    {
        $this->store->saveClient('dyn_1', 'https://shop.example/admin');
        $this->store->saveTokens($this->tokens('fmt_1', 'fmr_1'));
        $this->store->saveAccount('merchant@example.com', 'Merchant');

        $connection = $this->store->load();
        $credentials = $connection->credentials;

        self::assertSame('dyn_1', $credentials->clientId);
        self::assertSame('https://shop.example/admin', $credentials->redirectUri);
        self::assertSame('fmt_1', $credentials->accessToken);
        self::assertSame('fmr_1', $credentials->refreshToken);
        self::assertSame('org:read app:read', $credentials->scopes);
        self::assertSame('', $credentials->manualToken);
        self::assertTrue($credentials->isAppConnection());
        // A datetime column read back as the unix seconds the value objects work in.
        self::assertGreaterThan(time(), $credentials->expiresAt);
        self::assertLessThanOrEqual(time() + 900, $credentials->expiresAt);
        self::assertSame('merchant@example.com', $connection->accountEmail);
    }

    public function testAWriteByAnotherProcessIsVisibleAtOnce(): void
    {
        // The bug that cost a connection in production: the old storage answered from a
        // snapshot taken once per request, so the request that waited for the refresh
        // lock got back the refresh token it was about to present, presented it, and
        // fastmon ended the grant. Nothing memoises the row.
        $this->store->saveTokens($this->tokens('fmt_1', 'fmr_1'));
        self::assertSame('fmr_1', $this->store->credentials()->refreshToken);

        // A second store on the same repository stands in for the other process.
        (new ConnectionStore($this->repository, $this->systemConfig))->saveTokens($this->tokens('fmt_2', 'fmr_2'));

        self::assertSame('fmr_2', $this->store->credentials()->refreshToken);
        self::assertSame('fmt_2', $this->store->credentials()->accessToken);
    }

    public function testTheAuthorizationInFlightIsFiveColumnsAndIsCleared(): void
    {
        $this->store->saveAuthorization(new Authorization(
            state: str_repeat('a', 32),
            verifier: 'the-verifier',
            clientId: 'dyn_1',
            redirectUri: 'https://shop.example/admin',
            expiresAt: time() + 1800,
        ));

        $attempt = $this->store->authorization();

        self::assertNotNull($attempt);
        self::assertSame(str_repeat('a', 32), $attempt->state);
        self::assertSame('the-verifier', $attempt->verifier);
        self::assertSame('dyn_1', $attempt->clientId);
        self::assertSame('https://shop.example/admin', $attempt->redirectUri);
        self::assertFalse($attempt->isExpired());

        $this->store->clearAuthorization();

        self::assertNull($this->store->authorization());
    }

    public function testTheTwoRenderedIdsAreTheOnlyThingLeftInSystemConfig(): void
    {
        $this->store->saveApplication('org-7', 'app-1', 'src123', 'pix123');

        // The pair the storefront templates read, where a write invalidates the pages
        // that carry the old id. Everything else about the link is a column.
        self::assertSame('src123', $this->systemConfig->get(ConfigResolver::DOMAIN . 'sourceHash'));
        self::assertSame('pix123', $this->systemConfig->get(ConfigResolver::DOMAIN . 'collectorHash'));
        self::assertNull($this->systemConfig->get(ConfigResolver::DOMAIN . 'applicationId'));
        self::assertNull($this->systemConfig->get(ConfigResolver::DOMAIN . 'oauthRefreshToken'));

        $connection = $this->store->load();
        self::assertSame('app-1', $connection->applicationId);
        self::assertSame('org-7', $connection->organizationId);
        self::assertSame('src123', $connection->sourceHash);
    }

    public function testADisconnectKeepsTheRegistrationAndAnUninstallDoesNot(): void
    {
        $this->store->saveClient('dyn_1', 'https://shop.example/admin');
        $this->store->saveTokens($this->tokens('fmt_1', 'fmr_1'));
        $this->store->saveApplication('org-7', 'app-1', 'src123', 'pix123');

        $this->store->clear();

        // Not a credential, and re-using it keeps this shop one entry in fastmon's
        // connection list rather than a new one per reconnect.
        self::assertSame('dyn_1', $this->store->credentials()->clientId);
        self::assertSame('', $this->store->credentials()->refreshToken);
        self::assertFalse($this->store->load()->isConnected());
        self::assertSame('', $this->store->load()->sourceHash);

        $this->store->clearAll();

        self::assertSame('', $this->store->credentials()->clientId);
        self::assertSame(0, $this->rowCount());
    }

    public function testTheCredentialCannotBeReadThroughTheApi(): void
    {
        $this->store->saveTokens($this->tokens('fmt_1', 'fmr_1'));

        // What an administration user with `system_config:read` could do to the old
        // storage: read the domain and get the tokens with it. The entity admits the
        // system scope only, so the generic entity API refuses before any row is loaded.
        $this->expectException(AccessDeniedHttpException::class);

        $this->repository->search(new Criteria(), new Context(new AdminApiSource(null)));
    }

    private function rowCount(): int
    {
        return $this->repository->search(new Criteria(), new Context(new SystemSource()))->getTotal();
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
