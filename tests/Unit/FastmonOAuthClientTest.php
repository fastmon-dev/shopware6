<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\Api\FastmonApiException;
use Fastmon\Collector\Api\FastmonOAuthClient;
use Fastmon\Collector\Api\FastmonOAuthException;
use Fastmon\Collector\Api\OAuthUnavailableException;
use Fastmon\Collector\Api\Pkce;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class FastmonOAuthClientTest extends TestCase
{
    private const BASE = 'https://api.fastmon.eu';

    /** @var list<MockResponse> */
    private array $sent = [];

    public function testEveryEndpointComesFromTheDiscoveryDocument(): void
    {
        // Nothing is assembled from the base URL. The OAuth routes do not carry the API
        // prefix the rest of the plugin uses, and a plugin in the wild cannot be
        // redeployed when a path moves - a document it fetches can say so.
        $client = $this->client([$this->discovery()]);

        $metadata = $client->metadata(self::BASE);

        self::assertSame('https://api.fastmon.eu/auth/app/token', $metadata->tokenEndpoint);
        self::assertSame('https://api.fastmon.eu/auth/app/register', $metadata->registrationEndpoint);
        self::assertSame('https://api.fastmon.eu/auth/app/revoke', $metadata->revocationEndpoint);
        self::assertSame(
            self::BASE . '/.well-known/oauth-authorization-server',
            $this->sent[0]->getRequestUrl()
        );
    }

    public function testDiscoveryIsFetchedOncePerRequest(): void
    {
        // A connect flow touches it twice and a refresh once; the answer cannot change in
        // between, and a second round trip on every refresh would be a failure mode for
        // nothing.
        $client = $this->client([
            $this->discovery(),
            new MockResponse(json_encode(['client_id' => 'dyn_1'], \JSON_THROW_ON_ERROR), ['http_code' => 201]),
        ]);

        $client->metadata(self::BASE);
        $client->register(self::BASE, 'fastmon for Shopware', 'https://shop.example.com/admin', 'app:read');

        self::assertCount(2, $this->sent);
    }

    public function testAnInstanceWithoutTheEndpointsIsReportedDistinctly(): void
    {
        // The answer to this is specific - paste a key instead - so it must not be
        // indistinguishable from a generic failure.
        $client = $this->client([new MockResponse('', ['http_code' => 404])]);

        $this->expectException(OAuthUnavailableException::class);
        $client->metadata(self::BASE);
    }

    public function testAnIncompleteDiscoveryDocumentIsTreatedAsNoEndpointsAtAll(): void
    {
        // An instance that publishes metadata without a registration endpoint cannot
        // serve a self-registering client, which is the same dead end as having none.
        $client = $this->client([new MockResponse(json_encode([
            'issuer' => self::BASE,
            'authorization_endpoint' => self::BASE . '/auth/app/authorize',
            'token_endpoint' => self::BASE . '/auth/app/token',
        ], \JSON_THROW_ON_ERROR), ['http_code' => 200])]);

        $this->expectException(OAuthUnavailableException::class);
        $client->metadata(self::BASE);
    }

    public function testADocumentForAnotherIssuerIsRefused(): void
    {
        // RFC 8414 section 3.3. A document naming someone else is a document that would
        // send this shop's code and refresh token to someone else.
        $client = $this->client([new MockResponse(json_encode([
            'issuer' => 'https://evil.example',
            'authorization_endpoint' => self::BASE . '/auth/app/authorize',
            'token_endpoint' => self::BASE . '/auth/app/token',
            'registration_endpoint' => self::BASE . '/auth/app/register',
        ], \JSON_THROW_ON_ERROR), ['http_code' => 200])]);

        $this->expectException(FastmonApiException::class);
        $this->expectExceptionMessage('different issuer');
        $client->metadata(self::BASE);
    }

    public function testAnEndpointOutsideTheIssuerIsRefused(): void
    {
        // The issuer can be right and one endpoint still point elsewhere; the token
        // endpoint is where the verifier and the refresh token go.
        $client = $this->client([new MockResponse(json_encode([
            'issuer' => self::BASE,
            'authorization_endpoint' => self::BASE . '/auth/app/authorize',
            'token_endpoint' => 'https://evil.example/auth/app/token',
            'registration_endpoint' => self::BASE . '/auth/app/register',
        ], \JSON_THROW_ON_ERROR), ['http_code' => 200])]);

        $this->expectException(FastmonApiException::class);
        $this->expectExceptionMessage('outside its issuer');
        $client->metadata(self::BASE);
    }

    public function testATrailingSlashOnTheIssuerIsNotADifferentIssuer(): void
    {
        $client = $this->client([new MockResponse(json_encode([
            'issuer' => self::BASE . '/',
            'authorization_endpoint' => self::BASE . '/auth/app/authorize',
            'token_endpoint' => self::BASE . '/auth/app/token',
            'registration_endpoint' => self::BASE . '/auth/app/register',
        ], \JSON_THROW_ON_ERROR), ['http_code' => 200])]);

        self::assertSame(self::BASE . '/auth/app/token', $client->metadata(self::BASE)->tokenEndpoint);
    }

    public function testResetForgetsTheDiscoveryDocument(): void
    {
        // The Messenger worker keeps the container across messages; without the reset a
        // moved endpoint would be missed until the worker restarts.
        $client = $this->client([$this->discovery(), $this->discovery()]);

        $client->metadata(self::BASE);
        $client->reset();
        $client->metadata(self::BASE);

        self::assertCount(2, $this->sent);
    }

    public function testRegistrationDeclaresOneExactRedirectUriAndNoSecret(): void
    {
        $client = $this->client([
            $this->discovery(),
            new MockResponse(json_encode(['client_id' => 'dyn_42'], \JSON_THROW_ON_ERROR), ['http_code' => 201]),
        ]);

        $clientId = $client->register(
            self::BASE,
            'fastmon for Shopware (shop.example.com)',
            'https://shop.example.com/admin',
            'org:read app:read'
        );

        self::assertSame('dyn_42', $clientId);

        $body = $this->json($this->sent[1]);

        self::assertSame(['https://shop.example.com/admin'], $body['redirect_uris']);
        self::assertSame('org:read app:read', $body['scope']);
        // A shop has to keep reporting after the employee who approved it leaves.
        self::assertSame('organization', $body['credential_owner']);
        self::assertArrayNotHasKey('client_secret', $body);
    }

    public function testTheAuthorizationUrlCarriesTheChallengeAndNeverTheVerifier(): void
    {
        $client = $this->client([$this->discovery()]);
        $verifier = Pkce::verifier();

        $url = $client->authorizationUrl(
            self::BASE,
            'dyn_1',
            'https://shop.example.com/admin',
            'st4te',
            Pkce::challenge($verifier)
        );

        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        self::assertSame('code', $query['response_type']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame(Pkce::challenge($verifier), $query['code_challenge']);
        self::assertStringNotContainsString($verifier, $url);
    }

    public function testTheCodeExchangeReadsTheGrantedScopeAndTheOrganization(): void
    {
        $client = $this->client([
            $this->discovery(),
            new MockResponse(json_encode([
                'access_token' => 'fmt_new',
                'refresh_token' => 'fmr_new',
                'expires_in' => 900,
                // Less than was asked for: the approver ticked fewer boxes, which is the
                // normal case and the reason this is read rather than assumed.
                'scope' => 'org:read app:read',
                'account' => ['email' => 'merchant@example.com', 'name' => 'Merchant'],
                'organization' => ['id' => 'org-7', 'name' => 'Acme'],
            ], \JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);

        $tokens = $client->exchangeCode(self::BASE, 'dyn_1', 'the-code', 'https://shop.example.com/admin', 'verifier');

        self::assertSame('fmt_new', $tokens->accessToken);
        self::assertSame('fmr_new', $tokens->refreshToken);
        self::assertSame(900, $tokens->expiresIn);
        self::assertSame('org:read app:read', $tokens->scope);
        self::assertSame('org-7', $tokens->organizationId);

        // Form encoded, and the verifier is what authenticates the exchange: there is no
        // client secret to send.
        $body = $this->form($this->sent[1]);
        self::assertSame('authorization_code', $body['grant_type']);
        self::assertSame('verifier', $body['code_verifier']);
        self::assertArrayNotHasKey('client_secret', $body);
    }

    public function testAnOAuthErrorKeepsItsCodeApartFromItsProse(): void
    {
        // `error` is the field a client branches on; `error_description` is prose that may
        // be reworded at any time. Confusing the two is how a client ends up matching on
        // a sentence.
        $client = $this->client([
            $this->discovery(),
            new MockResponse(json_encode([
                'error' => 'invalid_grant',
                'error_description' => 'Invalid or expired refresh token.',
            ], \JSON_THROW_ON_ERROR), ['http_code' => 400]),
        ]);

        try {
            $client->refresh(self::BASE, 'dyn_1', 'fmr_spent');
        } catch (FastmonOAuthException $e) {
            self::assertSame('invalid_grant', $e->error);
            self::assertStringContainsString('Invalid or expired refresh token.', $e->getMessage());

            return;
        }

        self::fail('a refused refresh must raise');
    }

    public function testATokenResponseWithoutAnAccessTokenIsRefused(): void
    {
        $client = $this->client([
            $this->discovery(),
            new MockResponse(json_encode(['refresh_token' => 'fmr_new'], \JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);

        $this->expectException(FastmonApiException::class);
        $client->refresh(self::BASE, 'dyn_1', 'fmr_old');
    }

    public function testRevokingSendsTheTokenAndSurvivesAnyAnswer(): void
    {
        // RFC 7009 §2.2: unknown, expired, already revoked and somebody else's token all
        // answer 200. A shop disconnecting must not be stopped by any of it.
        $client = $this->client([
            $this->discovery(),
            new MockResponse('', ['http_code' => 200]),
        ]);

        $client->revoke(self::BASE, 'dyn_1', 'fmr_old');

        $body = $this->form($this->sent[1]);
        self::assertSame('fmr_old', $body['token']);
        self::assertSame('dyn_1', $body['client_id']);
    }

    public function testAnInstanceWithoutARevocationEndpointIsNotAnError(): void
    {
        // It only means the connection cannot be ended from this side, which costs the
        // merchant nothing they can see - the tokens are dropped here either way.
        $client = $this->client([new MockResponse(json_encode([
            'issuer' => self::BASE,
            'authorization_endpoint' => self::BASE . '/auth/app/authorize',
            'token_endpoint' => self::BASE . '/auth/app/token',
            'registration_endpoint' => self::BASE . '/auth/app/register',
        ], \JSON_THROW_ON_ERROR), ['http_code' => 200])]);

        $client->revoke(self::BASE, 'dyn_1', 'fmr_old');

        self::assertCount(1, $this->sent);
    }

    /**
     * The JSON body of a request that was sent.
     *
     * @return array<mixed>
     */
    private function json(MockResponse $response): array
    {
        $body = $response->getRequestOptions()['body'] ?? null;
        $decoded = json_decode(\is_string($body) ? $body : '[]', true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * The form-encoded body of a request that was sent.
     *
     * @return array<mixed>
     */
    private function form(MockResponse $response): array
    {
        $body = $response->getRequestOptions()['body'] ?? null;
        parse_str(\is_string($body) ? $body : '', $parsed);

        return $parsed;
    }

    private function discovery(): MockResponse
    {
        return new MockResponse(json_encode([
            'issuer' => self::BASE,
            'authorization_endpoint' => self::BASE . '/auth/app/authorize',
            'token_endpoint' => self::BASE . '/auth/app/token',
            'registration_endpoint' => self::BASE . '/auth/app/register',
            'revocation_endpoint' => self::BASE . '/auth/app/revoke',
            'code_challenge_methods_supported' => ['S256'],
        ], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function client(array $responses): FastmonOAuthClient
    {
        $this->sent = $responses;

        return new FastmonOAuthClient(new MockHttpClient($responses));
    }
}
