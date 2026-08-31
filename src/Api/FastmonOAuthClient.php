<?php declare(strict_types=1);

namespace Fastmon\Collector\Api;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The OAuth side of fastmon: registering this installation, sending the merchant to
 * consent, and turning the result into tokens.
 *
 * ## Why this is a second client
 *
 * `FastmonClient` speaks fastmon's own API - JSON bodies, bearer auth, an error envelope
 * with `code`, `message` and `request_id`, everything under `/v1`. Not one of those holds
 * here. The OAuth endpoints take form-encoded bodies, authenticate with PKCE rather than
 * a bearer token, answer failures in the OAuth wire format (RFC 6749 §5.2), and live at
 * whatever paths the discovery document names. Folding two protocols into one class would
 * mean a branch in every method for which of them applies.
 *
 * ## The shop is a public client
 *
 * A plugin ships as readable code on someone else's server: it can keep no secret, and
 * its redirect URI is the merchant's own domain, which nobody can register in advance.
 * So each installation registers itself (RFC 7591), gets its own `client_id`, and proves
 * the code exchange with PKCE (RFC 7636) instead of a client secret. There is nothing
 * confidential in the plugin source, which is the only arrangement that can be true of
 * something distributed through a store.
 */
final class FastmonOAuthClient
{
    use ReadsJsonResponses;

    /** RFC 8414. Defined relative to the origin, so it never carries the API prefix. */
    private const DISCOVERY_PATH = '/.well-known/oauth-authorization-server';

    /**
     * The connection belongs to the shop, not to the person who approved it: a shop has
     * to keep reporting after an employee leaves. fastmon asks the approver for
     * `org_key:manage` in return, which is the same authority as issuing an organization
     * key - correct, because that is what this produces.
     */
    private const CREDENTIAL_OWNER = 'organization';

    /**
     * Same budget as the rest of the plugin's calls: slow enough that a busy backend
     * still answers, fast enough that a stalled one does not hang the admin panel.
     */
    private const TIMEOUT_SECONDS = 10;

    /**
     * Discovery answers per base URL, for the lifetime of this request.
     *
     * Not persisted: a stored endpoint would keep working after fastmon moved one, which
     * is precisely the failure the discovery document exists to prevent. Not re-fetched
     * within a request either, because a connect flow touches it twice and a refresh
     * once, and the answer cannot change in between.
     *
     * @var array<string, OAuthMetadata>
     */
    private array $metadata = [];

    public function __construct(
        #[Autowire(service: 'fastmon_collector.http_client')]
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Where this fastmon instance keeps its OAuth endpoints.
     *
     * @throws OAuthUnavailableException when there is no discovery document, which is what
     *                                   an instance older than app connections answers
     */
    public function metadata(string $baseUrl): OAuthMetadata
    {
        $baseUrl = rtrim($baseUrl, '/');

        if (isset($this->metadata[$baseUrl])) {
            return $this->metadata[$baseUrl];
        }

        $response = $this->send('GET', $baseUrl . self::DISCOVERY_PATH, []);

        if ($response->getStatusCode() === 404) {
            throw new OAuthUnavailableException(
                'This fastmon instance does not offer app connections yet.'
            );
        }

        if ($response->getStatusCode() !== 200) {
            throw new FastmonApiException(
                'fastmon did not publish its OAuth endpoints: HTTP ' . $response->getStatusCode()
            );
        }

        $data = $this->decode($response);

        $metadata = new OAuthMetadata(
            issuer: $this->str($data, 'issuer'),
            authorizationEndpoint: $this->str($data, 'authorization_endpoint'),
            tokenEndpoint: $this->str($data, 'token_endpoint'),
            registrationEndpoint: $this->str($data, 'registration_endpoint'),
            // Optional: an instance that advertises none cannot be told about a
            // disconnect, and the connection is then dropped locally only.
            revocationEndpoint: $this->str($data, 'revocation_endpoint'),
        );

        if ($metadata->authorizationEndpoint === '' || $metadata->tokenEndpoint === '' || $metadata->registrationEndpoint === '') {
            throw new OAuthUnavailableException(
                'This fastmon instance does not offer self-registering app connections yet.'
            );
        }

        return $this->metadata[$baseUrl] = $metadata;
    }

    /**
     * Register this installation and return its `client_id` (RFC 7591).
     *
     * Once per shop, not once per connect: the registration is the shop's identity in
     * fastmon's connection list, and re-registering would leave a trail of clients nobody
     * can tell apart. It is repeated only when the redirect URI changes, because that is
     * the one field a registration cannot be corrected in.
     *
     * There is no client secret in the response and none is expected. What bounds a
     * registration is that it is never believed: unverified, one exact redirect URI, and
     * a consent screen the approver still has to pass - where the domain they judge is
     * their own shop.
     */
    public function register(string $baseUrl, string $clientName, string $redirectUri, string $scope): string
    {
        $response = $this->send('POST', $this->metadata($baseUrl)->registrationEndpoint, [
            'json' => [
                'client_name' => $clientName,
                'redirect_uris' => [$redirectUri],
                'scope' => $scope,
                'credential_owner' => self::CREDENTIAL_OWNER,
            ],
        ]);

        $status = $response->getStatusCode();

        if ($status !== 200 && $status !== 201) {
            $this->fail($response, 'fastmon refused this shop\'s registration');
        }

        $clientId = $this->str($this->decode($response), 'client_id');

        if ($clientId === '') {
            throw new FastmonApiException('fastmon registered this shop without returning a client_id');
        }

        return $clientId;
    }

    /**
     * Where to send the merchant. Everything in it is public except by omission: the
     * verifier behind `code_challenge` stays on the shop, and `state` is what ties the
     * answer back to the attempt this shop started.
     */
    public function authorizationUrl(
        string $baseUrl,
        string $clientId,
        string $redirectUri,
        string $state,
        string $codeChallenge,
    ): string {
        return $this->metadata($baseUrl)->authorizationEndpoint . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Redeem the authorization code. Single-use and short-lived, so a failure here is
     * final: the merchant goes through consent again rather than retrying.
     */
    public function exchangeCode(
        string $baseUrl,
        string $clientId,
        string $code,
        string $redirectUri,
        string $codeVerifier,
    ): OAuthTokens {
        return $this->token($baseUrl, [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ]);
    }

    /**
     * Trade the refresh token for the next pair.
     *
     * The response carries a **new** refresh token and the one presented is spent. There
     * is no grace period on fastmon's side and there must not be: a token presented twice
     * means two parties hold it, and fastmon ends the connection rather than guess which
     * one is the thief. Storing the successor before the access token is used is
     * therefore the caller's job, and it is not optional - see `AccessTokenProvider`.
     */
    public function refresh(string $baseUrl, string $clientId, string $refreshToken): OAuthTokens
    {
        return $this->token($baseUrl, [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * Hand a token back (RFC 7009). A refresh token ends the whole connection; an access
     * token only revokes itself.
     *
     * Always succeeds as far as the caller is concerned. The endpoint answers 200 for an
     * unknown, expired or already revoked token by design, and a shop that cannot reach
     * fastmon while disconnecting still has to be able to disconnect.
     */
    public function revoke(string $baseUrl, string $clientId, string $token): void
    {
        $endpoint = $this->metadata($baseUrl)->revocationEndpoint;

        if ($endpoint === '') {
            return;
        }

        $this->send('POST', $endpoint, [
            'body' => [
                'token' => $token,
                'client_id' => $clientId,
            ],
        ]);
    }

    /**
     * @param array<string, string> $form
     */
    private function token(string $baseUrl, array $form): OAuthTokens
    {
        $response = $this->send('POST', $this->metadata($baseUrl)->tokenEndpoint, ['body' => $form]);

        if ($response->getStatusCode() !== 200) {
            $this->fail($response, 'fastmon refused the token request');
        }

        $data = $this->decode($response);
        $accessToken = $this->str($data, 'access_token');

        if ($accessToken === '') {
            throw new FastmonApiException('fastmon returned a token response without an access_token');
        }

        $account = \is_array($data['account'] ?? null) ? $data['account'] : [];
        $organization = \is_array($data['organization'] ?? null) ? $data['organization'] : [];

        return new OAuthTokens(
            accessToken: $accessToken,
            refreshToken: $this->str($data, 'refresh_token'),
            // A response without one would mean an access token of unknown lifetime;
            // fastmon's is 15 minutes, and assuming less only costs a refresh.
            expiresIn: max(60, $this->int($data, 'expires_in', 300)),
            scope: $this->str($data, 'scope'),
            accountEmail: $this->str($account, 'email'),
            accountName: $this->str($account, 'name'),
            organizationId: $this->str($organization, 'id'),
            organizationName: $this->str($organization, 'name'),
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function send(string $method, string $url, array $options): ResponseInterface
    {
        $options['headers'] = ['Accept' => 'application/json'];
        $options['timeout'] = self::TIMEOUT_SECONDS;

        try {
            $response = $this->httpClient->request($method, $url, $options);
            // Complete the transport here, so a connection failure surfaces as our own
            // exception rather than from whatever line first reads the response.
            $response->getStatusCode();

            return $response;
        } catch (HttpExceptionInterface $e) {
            throw new FastmonApiException('Could not reach fastmon: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Raise the OAuth error the response carries.
     *
     * `error` is the field a client is meant to branch on and `error_description` is
     * prose that may be reworded at any time, so the two are kept apart: the code travels
     * on the exception, the description only reaches a human.
     */
    private function fail(ResponseInterface $response, string $context): never
    {
        $error = '';
        $description = '';

        try {
            $body = $this->decode($response);
            $error = $this->str($body, 'error');
            $description = $this->str($body, 'error_description');
        } catch (FastmonApiException) {
            // Not JSON: the status is all there is to report.
        }

        $detail = $error !== '' ? $error : 'HTTP ' . $response->getStatusCode();

        throw new FastmonOAuthException(
            $error,
            $context . ': ' . ($description !== '' ? $detail . ' (' . $description . ')' : $detail)
        );
    }
}
