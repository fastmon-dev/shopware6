<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\Api\FastmonApiException;
use Fastmon\Collector\Api\FastmonClient;
use Fastmon\Collector\Api\FastmonOAuthClient;
use Fastmon\Collector\Connection\ConnectionService;
use Fastmon\Collector\Connection\ConnectionStore;
use Fastmon\Collector\Connection\OAuthSession;
use Fastmon\Collector\Connection\RedirectUri;
use Fastmon\Collector\Service\ConfigResolver;
use Fastmon\Collector\Tests\Unit\Fake\StoresSystemConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ConnectionServiceTest extends TestCase
{
    use StoresSystemConfig;

    private const BASE = 'https://api.fastmon.eu';
    private const ADMIN = 'https://shop.example.com/admin';

    /** @var list<MockResponse> */
    private array $oauthCalls = [];

    private MockHttpClient $oauthHttp;

    /** @var array{mixed, mixed} */
    private array $appUrl;

    protected function setUp(): void
    {
        $this->appUrl = [$_SERVER['APP_URL'] ?? null, $_ENV['APP_URL'] ?? null];
        $_SERVER['APP_URL'] = 'https://shop.example.com';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['APP_URL'], $_ENV['APP_URL']);
        [$server, $env] = $this->appUrl;

        if ($server !== null) {
            $_SERVER['APP_URL'] = $server;
        }

        if ($env !== null) {
            $_ENV['APP_URL'] = $env;
        }
    }

    public function testConnectingRegistersThisShopAndSendsOnlyTheChallenge(): void
    {
        $service = $this->service(oauth: [$this->discovery(), $this->registration('dyn_1')]);

        $started = $service->beginAuthorization(self::ADMIN);

        parse_str((string) parse_url($started['authorizeUrl'], \PHP_URL_QUERY), $query);

        self::assertSame('dyn_1', $query['client_id']);
        self::assertSame(self::ADMIN, $query['redirect_uri']);
        self::assertSame('S256', $query['code_challenge_method']);

        // The verifier is the only thing authenticating the exchange, so it stays on the
        // shop: not in the URL, not in the answer to the browser.
        self::assertStringNotContainsString($this->attempt()['verifier'], $started['authorizeUrl']);
        self::assertSame(['authorizeUrl'], array_keys($started));

        self::assertSame('dyn_1', $this->stored[ConfigResolver::DOMAIN . 'oauthClientId']);
    }

    public function testTheRegistrationIsReusedRatherThanRepeated(): void
    {
        // A `client_id` is how this shop appears in fastmon's connection list. Registering
        // again on every connect would leave a trail of clients nobody can tell apart -
        // and would run into the registration rate limit on a shop that reconnects twice.
        $service = $this->service(oauth: [$this->discovery(), $this->registration('dyn_1')]);

        $service->beginAuthorization(self::ADMIN);
        $service->beginAuthorization(self::ADMIN);

        // Discovery and one registration, and nothing more on the second pass.
        self::assertSame(2, $this->oauthHttp->getRequestsCount(), 'a second connect must not register again');
    }

    public function testAChangedAddressRegistersAgain(): void
    {
        // The redirect URI is the one field a registration cannot be corrected in, so a
        // shop that moved domain needs a new client rather than a broken one.
        $this->stored = [
            ConfigResolver::DOMAIN . 'oauthClientId' => 'dyn_old',
            ConfigResolver::DOMAIN . 'oauthRedirectUri' => 'https://old.example.com/admin',
        ];

        $service = $this->service(oauth: [$this->discovery(), $this->registration('dyn_new')]);
        $service->beginAuthorization(self::ADMIN);

        self::assertSame('dyn_new', $this->stored[ConfigResolver::DOMAIN . 'oauthClientId']);
        self::assertSame(self::ADMIN, $this->stored[ConfigResolver::DOMAIN . 'oauthRedirectUri']);
    }

    public function testTheCodeIsRedeemedWithTheStoredVerifierAndTheAttemptIsClosed(): void
    {
        $service = $this->service(oauth: [
            $this->discovery(),
            $this->registration('dyn_1'),
            new MockResponse(json_encode([
                'access_token' => 'fmt_new',
                'refresh_token' => 'fmr_new',
                'expires_in' => 900,
                'scope' => 'org:read app:read app:write site:read',
                'account' => ['email' => 'merchant@example.com', 'name' => 'Merchant'],
                'organization' => ['id' => 'org-7', 'name' => 'Acme'],
            ], \JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);

        $service->beginAuthorization(self::ADMIN);
        $attempt = $this->attempt();

        $connected = $service->completeAuthorization('the-code', $attempt['state']);

        self::assertSame('merchant@example.com', $connected['accountEmail']);
        self::assertSame('fmr_new', $this->stored[ConfigResolver::DOMAIN . 'oauthRefreshToken']);

        // Settled on fastmon's consent screen, so the shop never asks again - and can
        // never end up reporting to a different organization than the one approved.
        self::assertSame('org-7', $this->stored[ConfigResolver::DOMAIN . 'organizationId']);
        self::assertSame('Acme', $this->stored[ConfigResolver::DOMAIN . 'organizationName']);

        // The code is single-use, so an attempt left open is one an intercepted code
        // could still be redeemed against.
        self::assertArrayNotHasKey(OAuthSession::KEY, $this->stored);

        $body = $this->form($this->oauthCalls[2]);
        self::assertSame($attempt['verifier'], $body['code_verifier']);
        self::assertSame(self::ADMIN, $body['redirect_uri']);
    }

    public function testACallbackThisShopDidNotStartIsRefused(): void
    {
        // The state is what ties an answer back to an attempt. Without a matching one
        // there is no verifier, and redeeming anything would be redeeming a stranger's
        // code.
        $service = $this->service();

        $this->expectException(FastmonApiException::class);

        try {
            $service->completeAuthorization('the-code', str_repeat('a', 32));
        } finally {
            self::assertArrayNotHasKey(ConfigResolver::DOMAIN . 'oauthRefreshToken', $this->stored);
        }
    }

    public function testADeclinedConsentClosesTheAttemptAndSaysWhatHappened(): void
    {
        $service = $this->service(oauth: [$this->discovery(), $this->registration('dyn_1')]);
        $service->beginAuthorization(self::ADMIN);

        $this->expectExceptionMessage('declined');

        try {
            $service->declineAuthorization($this->attempt()['state'], 'access_denied');
        } finally {
            self::assertArrayNotHasKey(OAuthSession::KEY, $this->stored);
        }
    }

    public function testDisconnectingHandsTheConnectionBackToFastmon(): void
    {
        // Revoking the refresh token ends the grant on fastmon's side, so the shop cannot
        // rotate its way back in and the entry disappears from the merchant's connection
        // list without them having to go and remove it.
        $this->connected();

        $this->service(oauth: [$this->discovery(), new MockResponse('', ['http_code' => 200])])->disconnect();

        $body = $this->form($this->oauthCalls[1]);
        self::assertSame('fmr_live', $body['token']);
        self::assertArrayNotHasKey(ConfigResolver::DOMAIN . 'oauthRefreshToken', $this->stored);
        self::assertArrayNotHasKey(ConfigResolver::DOMAIN . 'trackerId', $this->stored);

        // Not a credential, and re-using it keeps this shop one entry in that list rather
        // than a new one per reconnect.
        self::assertSame('dyn_1', $this->stored[ConfigResolver::DOMAIN . 'oauthClientId']);
    }

    public function testAShopThatCannotReachFastmonCanStillDisconnect(): void
    {
        $this->connected();

        $this->service(oauth: [new MockResponse('', ['http_code' => 500])])->disconnect();

        self::assertArrayNotHasKey(ConfigResolver::DOMAIN . 'oauthRefreshToken', $this->stored);
    }

    public function testAKeyIsVerifiedBeforeItIsStored(): void
    {
        $service = $this->service(api: [new MockResponse('', ['http_code' => 401])]);

        $this->expectExceptionMessage('fastmon organization list failed');

        try {
            $service->connectWithToken('fmo_typo');
        } finally {
            // A typo must be reported as a typo, not stored to become a storefront that
            // quietly never provisions.
            self::assertArrayNotHasKey(ConfigResolver::DOMAIN . 'apiToken', $this->stored);
        }
    }

    public function testAKeyBoundToOneOrganizationSettlesItWithoutAsking(): void
    {
        $service = $this->service(api: [$this->organizations([['id' => 'org-7', 'name' => 'Acme']])]);

        $service->connectWithToken('fmo_key');

        self::assertSame('fmo_key', $this->stored[ConfigResolver::DOMAIN . 'apiToken']);
        self::assertSame('org-7', $this->stored[ConfigResolver::DOMAIN . 'organizationId']);
    }

    private function connected(string $applicationId = ''): void
    {
        $this->stored = [
            ConfigResolver::DOMAIN . 'oauthClientId' => 'dyn_1',
            ConfigResolver::DOMAIN . 'oauthRedirectUri' => self::ADMIN,
            ConfigResolver::DOMAIN . 'oauthRefreshToken' => 'fmr_live',
            ConfigResolver::DOMAIN . 'oauthScopes' => 'org:read app:read app:write site:read',
            ConfigResolver::DOMAIN . 'oauthExpiresAt' => (string) (time() + 600),
            ConfigResolver::DOMAIN . 'oauthAccessToken' => 'fmt_live',
            ConfigResolver::DOMAIN . 'accountEmail' => 'merchant@example.com',
            ConfigResolver::DOMAIN . 'organizationId' => 'org-7',
            ConfigResolver::DOMAIN . 'organizationName' => 'Acme',
            ConfigResolver::DOMAIN . 'applicationId' => $applicationId,
            ConfigResolver::DOMAIN . 'trackerId' => 'src123',
        ];
    }

    /**
     * The authorization in flight, as it sits in `system_config`.
     *
     * @return array{state: string, verifier: string}
     */
    private function attempt(): array
    {
        $raw = $this->stored[OAuthSession::KEY] ?? null;
        $decoded = json_decode(\is_string($raw) ? $raw : '[]', true);
        $decoded = \is_array($decoded) ? $decoded : [];

        return [
            'state' => \is_string($decoded['state'] ?? null) ? $decoded['state'] : '',
            'verifier' => \is_string($decoded['verifier'] ?? null) ? $decoded['verifier'] : '',
        ];
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
        ], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }

    private function registration(string $clientId): MockResponse
    {
        return new MockResponse(
            json_encode(['client_id' => $clientId], \JSON_THROW_ON_ERROR),
            ['http_code' => 201]
        );
    }

    /**
     * @param list<array{id: string, name: string}> $organizations
     */
    private function organizations(array $organizations): MockResponse
    {
        return new MockResponse(
            json_encode(['data' => $organizations], \JSON_THROW_ON_ERROR),
            ['http_code' => 200]
        );
    }

    /**
     * @param list<MockResponse> $api
     * @param list<MockResponse> $oauth
     */
    private function service(array $api = [], array $oauth = []): ConnectionService
    {
        $this->oauthCalls = $oauth;
        $systemConfig = $this->systemConfig();
        $store = new ConnectionStore($systemConfig);
        $config = new ConfigResolver($systemConfig);
        $this->oauthHttp = new MockHttpClient($oauth);
        $oauthClient = new FastmonOAuthClient($this->oauthHttp);

        return new ConnectionService(
            new FastmonClient(new MockHttpClient($api)),
            $oauthClient,
            $store,
            new OAuthSession($systemConfig),
            new RedirectUri(),
            $config,
            new NullLogger(),
        );
    }
}
