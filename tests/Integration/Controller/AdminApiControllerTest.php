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

        // Read does not imply write: the token is exactly as sensitive as the payment
        // credentials next to it.
        $browser->request('POST', self::DISCONNECT);
        self::assertSame(Response::HTTP_FORBIDDEN, $browser->getResponse()->getStatusCode());
    }

    public function testTheUpdatePrivilegeOpensTheWriteRoutes(): void
    {
        $browser = $this->getBrowser(true, [], ['system_config:read', 'system_config:update']);

        $browser->request('POST', self::DISCONNECT);

        self::assertSame(Response::HTTP_OK, $browser->getResponse()->getStatusCode());
        self::assertTrue($this->json($browser)['success']);
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
