<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\Api\FastmonApiException;
use Fastmon\Collector\Api\FastmonClient;
use Fastmon\Collector\Api\FastmonCredentialExpiredException;
use Fastmon\Collector\Api\FastmonOrganizationNotApprovedException;
use Fastmon\Collector\Api\FastmonPermissionDeniedException;
use Fastmon\Collector\Api\FastmonUnauthorizedException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class FastmonClientTest extends TestCase
{
    private const BASE = 'https://api.fastmon.eu';

    public function testARevokedTokenIsReportedAsUnauthorized(): void
    {
        $client = $this->client(new MockResponse('', ['http_code' => 401]));

        $this->expectException(FastmonUnauthorizedException::class);
        $client->organizations(self::BASE, 'fmt_stale');
    }

    public function testAnUnapprovedOrganizationIsItsOwnState(): void
    {
        // fastmon gates ingestion behind an anti-abuse review, so a freshly registered
        // account connects fine and only then finds it cannot create an application.
        // The admin module renders this as "waiting", so it must not arrive as a
        // generic API failure.
        $client = $this->client(new MockResponse(json_encode([
            'error' => [
                'code' => 'organization_not_approved',
                'message' => 'This organization has not been approved yet.',
            ],
        ], \JSON_THROW_ON_ERROR), ['http_code' => 403]));

        try {
            $client->createApplication(self::BASE, 'fm_token', 'org-1', 'Shopware', 'prod', 'standard');
            self::fail('expected a FastmonOrganizationNotApprovedException');
        } catch (FastmonOrganizationNotApprovedException $e) {
            // fastmon's own wording already says what happens next, so it is passed
            // through rather than buried under our context.
            self::assertSame('This organization has not been approved yet.', $e->getMessage());
        }
    }

    public function testAMissingPermissionIsNamed(): void
    {
        // "403 Forbidden" sends a merchant to support; "this token is missing app:write"
        // sends them to the fastmon dashboard to fix it.
        $client = $this->client(new MockResponse(json_encode([
            'error' => [
                'code' => 'permission_denied',
                'message' => 'Missing permission: app:write.',
                'details' => ['permission' => 'app:write'],
            ],
        ], \JSON_THROW_ON_ERROR), ['http_code' => 403]));

        try {
            $client->createApplication(self::BASE, 'fm_token', 'org-1', 'Shopware', 'prod', 'standard');
            self::fail('expected a FastmonPermissionDeniedException');
        } catch (FastmonPermissionDeniedException $e) {
            self::assertSame('app:write', $e->permission);
        }
    }

    public function testAnExpiredCredentialSaysSoRatherThanJustFailing(): void
    {
        // Same reaction as any other 401 - connect again - but a merchant told "expired"
        // stops looking for what they broke.
        $client = $this->client(new MockResponse(json_encode([
            'error' => ['code' => 'credential_expired', 'message' => 'Key expired.'],
        ], \JSON_THROW_ON_ERROR), ['http_code' => 401]));

        $this->expectException(FastmonCredentialExpiredException::class);
        $client->organizations(self::BASE, 'fmt_old');
    }

    public function testALostOrganizationMembershipEndsInAReconnect(): void
    {
        // The consenting account left the organization. Nothing on this side repairs
        // that, so it must reach the reconnect path rather than look like a transient
        // API error the module would keep retrying.
        $client = $this->client(new MockResponse(json_encode([
            'error' => ['code' => 'organization_not_found', 'message' => 'No such organization'],
        ], \JSON_THROW_ON_ERROR), ['http_code' => 404]));

        $this->expectException(FastmonUnauthorizedException::class);
        $client->applications(self::BASE, 'fm_token', 'org-gone');
    }

    public function testSurfacesTheErrorEnvelopeIncludingTheRequestId(): void
    {
        $client = $this->client(new MockResponse(json_encode([
            'error' => [
                'code' => 'validation_error',
                'message' => 'Unknown preset',
                'request_id' => 'req-42',
            ],
        ], \JSON_THROW_ON_ERROR), ['http_code' => 400]));

        try {
            $client->applications(self::BASE, 'fm_token', 'org-1');
            self::fail('expected a FastmonApiException');
        } catch (FastmonApiException $e) {
            self::assertStringContainsString('validation_error', $e->getMessage());
            self::assertStringContainsString('Unknown preset', $e->getMessage());
            // The one string support needs to find the request in the backend logs.
            self::assertStringContainsString('req-42', $e->getMessage());
        }
    }

    public function testUnwrapsAPaginatedList(): void
    {
        $client = $this->client(new MockResponse(json_encode([
            'data' => [
                ['id' => 'org-1', 'name' => 'Acme'],
                ['id' => 'org-2', 'name' => 'Beta'],
            ],
            'meta' => ['total' => 2],
        ], \JSON_THROW_ON_ERROR), ['http_code' => 200]));

        self::assertSame(
            [['id' => 'org-1', 'name' => 'Acme'], ['id' => 'org-2', 'name' => 'Beta']],
            $client->organizations(self::BASE, 'fm_token')
        );
    }

    public function testCreatesAnApplicationThatProvisionsItsOwnDomains(): void
    {
        $sent = null;
        $client = new FastmonClient(new MockHttpClient(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $body = $options['body'] ?? '';
            $sent = ['method' => $method, 'url' => $url, 'body' => json_decode(\is_string($body) ? $body : '', true)];

            return new MockResponse(json_encode([
                'id' => 'app-1',
                'name' => 'Shopware',
                'source_hash' => 'src123',
                'collector_hash' => 'col456',
                'environment' => 'prod',
                'site_count' => 0,
            ], \JSON_THROW_ON_ERROR), ['http_code' => 201]);
        }));

        $application = $client->createApplication(self::BASE, 'fm_token', 'org-1', 'Shopware', 'prod', 'standard');

        self::assertIsArray($sent);
        self::assertSame('POST', $sent['method']);
        // Under /v1: production answers 404 on the unprefixed form.
        self::assertSame(self::BASE . '/v1/organizations/org-1/applications', $sent['url']);
        // The two values that make one application cover every sales channel without
        // the plugin ever enumerating them.
        $body = $sent['body'];
        self::assertIsArray($body);
        self::assertSame('auto', $body['site_policy']);
        self::assertSame('shopware6', $body['pagetype_ruleset']);

        // fastmon's wire names become the names the templates use.
        self::assertSame('src123', $application['trackerId']);
        self::assertSame('col456', $application['pixelId']);
    }

    public function testRefusesAnApplicationWithoutATrackerId(): void
    {
        $client = $this->client(new MockResponse(
            json_encode(['id' => 'app-1', 'name' => 'Shopware'], \JSON_THROW_ON_ERROR),
            ['http_code' => 201]
        ));

        $this->expectException(FastmonApiException::class);
        $client->createApplication(self::BASE, 'fm_token', 'org-1', 'Shopware', 'prod', 'standard');
    }

    public function testAnUnreachableApiIsAFastmonError(): void
    {
        // Not a raw transport exception: the admin module renders this message next to
        // the button that caused it.
        $client = new FastmonClient(new MockHttpClient(static function (): MockResponse {
            return new MockResponse([], ['error' => 'Connection refused']);
        }));

        $this->expectException(FastmonApiException::class);
        $client->organizations(self::BASE, 'fm_token');
    }

    private function client(MockResponse $response): FastmonClient
    {
        return new FastmonClient(new MockHttpClient($response));
    }
}
