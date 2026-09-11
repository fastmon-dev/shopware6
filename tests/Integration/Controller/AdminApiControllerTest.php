<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Integration\Controller;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\AdminFunctionalTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseHelper\TestBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * The routes are gated on the `system_config` ACL, and a gate that was never tested is a
 * gate nobody knows is closed. Each browser here is a real admin user holding exactly
 * the privileges named, so what is asserted is Shopware's own enforcement.
 */
final class AdminApiControllerTest extends TestCase
{
    use AdminFunctionalTestBehaviour;

    private const STATUS = '/api/_action/fastmon-collector/status';
    private const DISCONNECT = '/api/_action/fastmon-collector/disconnect';
    private const COLLECTION = '/api/_action/fastmon-collector/collection';
    private const CONNECT_START = '/api/_action/fastmon-collector/connect/start';
    private const CONNECT_CALLBACK = '/api/_action/fastmon-collector/connect/callback';
    private const CONNECT_TOKEN = '/api/_action/fastmon-collector/connect/token';
    private const ORGANIZATIONS = '/api/_action/fastmon-collector/organizations';
    private const APPLICATIONS = '/api/_action/fastmon-collector/applications';
    private const ATTACH = '/api/_action/fastmon-collector/applications/attach';
    private const SITES = '/api/_action/fastmon-collector/sites';
    private const SECRET = '/api/_action/fastmon-collector/collection/secret';
    private const SERVER_TIMING = '/api/_action/fastmon-collector/server-timing';

    /** Every route that only reads. `system_config:read` has to be enough for each. */
    private const READ_ROUTES = [
        self::STATUS,
        self::ORGANIZATIONS,
        self::APPLICATIONS . '?organizationId=org-7',
        self::SITES,
        self::COLLECTION,
        self::SERVER_TIMING,
    ];

    /**
     * Every route that writes, or reaches fastmon on the shop's behalf. Each needs
     * `system_config:update`, and each is exercised here without a connection, so no
     * request leaves the test: an unconnected shop is refused before any call is made.
     * `connect/start` is the exception (it would register the shop with fastmon), so it
     * is only ever driven up to the gate.
     */
    private const WRITE_ROUTES = [
        self::CONNECT_CALLBACK,
        self::CONNECT_TOKEN,
        self::DISCONNECT,
        self::APPLICATIONS,
        self::ATTACH,
        self::COLLECTION,
        self::SECRET,
    ];

    public function testEveryRouteIsClosedWithoutTheConfigPrivilege(): void
    {
        $browser = $this->getBrowser(true, [], ['product:read']);

        $browser->request('GET', self::STATUS);
        self::assertSame(Response::HTTP_FORBIDDEN, $browser->getResponse()->getStatusCode());

        $browser->request('POST', self::DISCONNECT);
        self::assertSame(Response::HTTP_FORBIDDEN, $browser->getResponse()->getStatusCode());
    }

    public function testReadingNeedsReadAndWritingNeedsUpdate(): void
    {
        $browser = $this->getBrowser(true, [], ['system_config:read']);

        $browser->request('GET', self::STATUS);
        self::assertSame(Response::HTTP_OK, $browser->getResponse()->getStatusCode());

        $body = $this->json($browser);
        self::assertTrue($body['success']);
        self::assertFalse($body['connected']);

        // Read does not imply write: the credential is exactly as sensitive as the
        // payment credentials next to it.
        $browser->request('POST', self::DISCONNECT);
        self::assertSame(Response::HTTP_FORBIDDEN, $browser->getResponse()->getStatusCode());

        // Starting a connection registers this shop with fastmon and writes a client id,
        // so it belongs on the same side of the gate.
        $browser->request('POST', self::CONNECT_START);
        self::assertSame(Response::HTTP_FORBIDDEN, $browser->getResponse()->getStatusCode());

        $browser->request('POST', self::CONNECT_CALLBACK);
        self::assertSame(Response::HTTP_FORBIDDEN, $browser->getResponse()->getStatusCode());
    }

    public function testTheUpdatePrivilegeOpensTheWriteRoutes(): void
    {
        $browser = $this->getBrowser(true, [], ['system_config:read', 'system_config:update']);

        $browser->request('POST', self::DISCONNECT);

        self::assertSame(Response::HTTP_OK, $browser->getResponse()->getStatusCode());
        self::assertTrue($this->json($browser)['success']);
    }

    public function testEveryReadRouteOpensWithReadAlone(): void
    {
        $browser = $this->getBrowser(true, [], ['system_config:read']);

        foreach (self::READ_ROUTES as $route) {
            $browser->request('GET', $route);
            self::assertSame(Response::HTTP_OK, $browser->getResponse()->getStatusCode(), $route);
        }
    }

    public function testEveryWriteRouteIsClosedToReadAlone(): void
    {
        $browser = $this->getBrowser(true, [], ['system_config:read']);

        foreach ([...self::WRITE_ROUTES, self::CONNECT_START] as $route) {
            $this->post($browser, $route, []);
            self::assertSame(Response::HTTP_FORBIDDEN, $browser->getResponse()->getStatusCode(), $route);
        }
    }

    public function testEveryWriteRouteOpensWithUpdate(): void
    {
        // 200 throughout, refusals included: the panel renders outcomes inline, and a
        // 4xx would be swallowed by the administration's global error handler.
        $browser = $this->getBrowser(true, [], ['system_config:read', 'system_config:update']);

        foreach (self::WRITE_ROUTES as $route) {
            $this->post($browser, $route, []);
            self::assertSame(Response::HTTP_OK, $browser->getResponse()->getStatusCode(), $route);
            self::assertIsBool($this->json($browser)['success'], $route);
        }
    }

    public function testAnUnconnectedShopIsToldToConnectNotToDebug(): void
    {
        // Every route that needs a credential answers with the same flag, which is the
        // one thing the panel turns into a connect button rather than an error.
        $browser = $this->getBrowser(true, [], ['system_config:read', 'system_config:update']);

        $browser->request('GET', self::ORGANIZATIONS);
        $this->assertReconnect($browser, self::ORGANIZATIONS);

        $browser->request('GET', self::APPLICATIONS . '?organizationId=org-7');
        $this->assertReconnect($browser, self::APPLICATIONS);

        $this->post($browser, self::APPLICATIONS, ['organizationId' => 'org-7', 'name' => 'Shopware']);
        $this->assertReconnect($browser, self::APPLICATIONS);

        $this->post($browser, self::ATTACH, ['organizationId' => 'org-7', 'applicationId' => 'app-1']);
        $this->assertReconnect($browser, self::ATTACH);
    }

    public function testWhatNeedsNoCredentialAnswersWithoutOne(): void
    {
        $browser = $this->getBrowser(true, [], ['system_config:read', 'system_config:update']);

        // No application linked means no domains to list, not a failure.
        $browser->request('GET', self::SITES);
        $body = $this->json($browser);
        self::assertTrue($body['success']);
        self::assertSame([], $body['sites']);

        // The measurement sources on this host are a fact about the machine.
        $browser->request('GET', self::SERVER_TIMING);
        $body = $this->json($browser);
        self::assertTrue($body['success']);
        self::assertIsArray($body['providers']);

        // The collection card describes what is stored; without a link it is the default.
        $browser->request('GET', self::COLLECTION);
        $body = $this->json($browser);
        self::assertTrue($body['success']);
        self::assertSame('default', $body['mode']);
    }

    public function testTheProxySecretNeedsALinkedApplication(): void
    {
        // fastmon issues the secret per application; without one there is nothing to
        // rotate, and the merchant is told that rather than shown a stack trace.
        $browser = $this->getBrowser(true, [], ['system_config:read', 'system_config:update']);

        $this->post($browser, self::SECRET, []);

        $body = $this->json($browser);
        self::assertFalse($body['success']);
        self::assertIsString($body['error']);
        self::assertNotSame('', $body['error']);
    }

    public function testAnEmptyKeyIsRefusedBeforeFastmonIsAsked(): void
    {
        $browser = $this->getBrowser(true, [], ['system_config:read', 'system_config:update']);

        $this->post($browser, self::CONNECT_TOKEN, ['token' => '   ']);

        $body = $this->json($browser);
        self::assertFalse($body['success']);
        self::assertSame('No token was given.', $body['error']);
    }

    public function testACallbackThisShopDidNotStartIsARefusalNotAFault(): void
    {
        // Whatever reaches the callback route without a matching attempt - a stale tab, a
        // bookmarked URL, somebody guessing - has no verifier behind it and cannot be
        // redeemed. The merchant reads a sentence and starts again; nothing here is a
        // fault in the shop.
        $browser = $this->getBrowser(true, [], ['system_config:read', 'system_config:update']);

        $browser->request(
            'POST',
            self::CONNECT_CALLBACK,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['code' => 'whatever', 'state' => str_repeat('a', 32)])
        );

        self::assertSame(Response::HTTP_OK, $browser->getResponse()->getStatusCode());

        $body = $this->json($browser);
        self::assertFalse($body['success']);
        self::assertIsString($body['error']);
        self::assertStringContainsString('no longer open', $body['error']);
    }

    public function testABodyValueThatIsNotAStringIsARefusalNotAFault(): void
    {
        // `{"mode": ["x"]}` used to be cast to the string "Array". Now it is an empty mode,
        // which the controller refuses with a message - and with 200, like every other
        // outcome the panel renders inline.
        $browser = $this->getBrowser(true, [], ['system_config:read', 'system_config:update']);

        $browser->request(
            'POST',
            self::COLLECTION,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['mode' => ['x']])
        );

        self::assertSame(Response::HTTP_OK, $browser->getResponse()->getStatusCode());

        $body = $this->json($browser);
        self::assertFalse($body['success']);
        self::assertSame('Unknown collection mode.', $body['error']);
    }

    private function assertReconnect(TestBrowser $browser, string $route): void
    {
        self::assertSame(Response::HTTP_OK, $browser->getResponse()->getStatusCode(), $route);

        $body = $this->json($browser);
        self::assertFalse($body['success'], $route);
        self::assertTrue($body['reconnect'] ?? false, $route);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(TestBrowser $browser, string $route, array $body): void
    {
        $browser->request(
            'POST',
            $route,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($body)
        );
    }

    /**
     * @return array<mixed>
     */
    private function json(TestBrowser $browser): array
    {
        $decoded = json_decode((string) $browser->getResponse()->getContent(), true);

        self::assertIsArray($decoded);

        return $decoded;
    }
}
