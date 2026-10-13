<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Doctrine\DBAL\Connection as DbalConnection;
use Fastmon\Collector\Api\FastmonClient;
use Fastmon\Collector\Api\FastmonOAuthClient;
use Fastmon\Collector\Collection\EndpointChecker;
use Fastmon\Collector\Connection\AccessTokenProvider;
use Fastmon\Collector\Connection\ConnectionStore;
use Fastmon\Collector\Provisioning\SiteSynchroniser;
use Fastmon\Collector\Provisioning\SyncSitesMessage;
use Fastmon\Collector\Provisioning\SyncSitesMessageHandler;
use Fastmon\Collector\Service\ConfigResolver;
use Fastmon\Collector\Tests\Unit\Fake\StoresConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class SyncSitesMessageHandlerTest extends TestCase
{
    use StoresConnection;

    private int $requests = 0;

    public function testAnUnlinkedShopIsLeftAlone(): void
    {
        // Nothing to put the domains on, so not even the list is asked for.
        $this->row = ['manualToken' => 'fmk_live'];

        $this->handler([])(new SyncSitesMessage());

        self::assertSame(0, $this->requests);
    }

    public function testALinkedShopSyncsAndAFailureIsNotAFailedMessage(): void
    {
        // fastmon answering 500 is retried by the next save and by the button, not by
        // the failure transport.
        $this->row = ['manualToken' => 'fmk_live', 'applicationId' => 'app-1'];

        $this->handler([new MockResponse('', ['http_code' => 500])])(new SyncSitesMessage());

        self::assertSame(1, $this->requests);
    }

    /**
     * @param list<MockResponse> $api
     */
    private function handler(array $api): SyncSitesMessageHandler
    {
        $http = new MockHttpClient(function () use (&$api): MockResponse {
            ++$this->requests;
            $next = array_shift($api);
            self::assertNotNull($next, 'more requests than responses');

            return $next;
        });

        $systemConfig = $this->systemConfig();
        $store = new ConnectionStore($this->connectionRepository(), $systemConfig);
        $config = new ConfigResolver($systemConfig);
        $database = $this->createMock(DbalConnection::class);
        $database->method('fetchFirstColumn')->willReturn(['https://shop.example']);

        $sync = new SiteSynchroniser(
            new FastmonClient($http),
            new AccessTokenProvider(new FastmonOAuthClient(new MockHttpClient([])), $store, $config, new LockFactory(new InMemoryStore()), new NullLogger()),
            $store,
            $config,
            new EndpointChecker(new MockHttpClient([]), $store, $database),
            new NullLogger(),
        );

        return new SyncSitesMessageHandler($sync, $store, new NullLogger());
    }
}
