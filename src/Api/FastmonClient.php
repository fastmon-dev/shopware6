<?php declare(strict_types=1);

namespace Fastmon\Collector\Api;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Talks to the fastmon API.
 *
 * ## Why this is not the flow the fastmon Shopware *app* uses
 *
 * The app runs the OAuth Authorization Code grant as a confidential client: its app
 * server holds a `client_secret` and has one fixed, pre-registered `redirect_uri`. A
 * plugin has neither. It runs on the merchant's own server, under a domain nobody can
 * register in advance, and a secret shipped inside a Store zip is a secret in every
 * shop that ever downloaded it.
 *
 * So this client uses the Device Authorization Grant (RFC 8628) instead - the same
 * OAuth 2.0 family, and the grant that exists precisely for a client that can hold no
 * secret and own no redirect. The shop asks for a code, the merchant approves it in
 * their own browser on fastmon, and the shop polls until a token comes back. Nothing
 * secret is distributed and no redirect URI has to exist.
 *
 * Until the fastmon backend serves that grant, `startDeviceAuthorization()` raises
 * `DeviceFlowUnsupportedException` and the merchant pastes a token from the dashboard
 * instead. Everything past the point where a token exists is identical either way,
 * which is why the rest of the plugin never learns which of the two happened.
 */
final class FastmonClient
{
    /** RFC 8628 §3.4. */
    private const DEVICE_GRANT = 'urn:ietf:params:oauth:grant-type:device_code';

    /**
     * Identifies this integration to fastmon. Public by design in the device grant -
     * RFC 8628 clients are public clients and authenticate nothing - so shipping it in
     * the plugin source is correct rather than merely tolerable. It lands in
     * `issued_via` on every token fastmon mints, which is what makes tokens filterable
     * and revocable per integration.
     */
    public const CLIENT_ID = 'shopware-plugin';

    /**
     * Every route lives under this prefix.
     *
     * fastmon's own notes describe the API as served unprefixed, with `/v1` kept alive
     * only as a legacy alias. That is where it is going, not where production is:
     * `GET /account` answers 404 on api.fastmon.eu today while `GET /v1/account` answers
     * 401, so the unprefixed form is the one that does not exist yet.
     *
     * `/v1` is the right choice permanently rather than a stopgap, because the prefix
     * stays valid after the unprefixed routes ship - a plugin in the wild cannot be
     * redeployed in step with the backend, and a prefix that works before and after is
     * worth more than one that is merely newer.
     *
     * Verified against the live API rather than inferred: the failure mode is a plugin
     * that reaches nothing while reporting a perfectly ordinary "not found".
     */
    private const PATH_PREFIX = '/v1';

    /**
     * Slow enough that a stalled shop never hangs a request, fast enough that the admin
     * module's poll does not look frozen.
     */
    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        #[Autowire(service: 'fastmon_collector.http_client')]
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Begin a device authorization. The caller shows `userCode` and
     * `verificationUriComplete` to the merchant, then polls `pollDeviceToken()`.
     */
    public function startDeviceAuthorization(string $baseUrl): DeviceAuthorization
    {
        $response = $this->request('POST', $baseUrl, '/auth/app/device/code', [
            'body' => ['client_id' => self::CLIENT_ID],
        ]);

        $status = $response->getStatusCode();

        // An instance that predates the grant answers 404 (no route) or 405 (the path
        // exists for another method). Both mean the same thing to the merchant.
        if ($status === 404 || $status === 405) {
            throw new DeviceFlowUnsupportedException(
                'This fastmon instance does not offer the device authorization flow yet.'
            );
        }

        if ($status !== 200) {
            $this->fail($response, 'fastmon device authorization failed');
        }

        $data = $this->decode($response);

        $deviceCode = $this->str($data, 'device_code');
        $userCode = $this->str($data, 'user_code');
        $verificationUri = $this->str($data, 'verification_uri');

        if ($deviceCode === '' || $userCode === '' || $verificationUri === '') {
            throw new FastmonApiException('fastmon device authorization returned an incomplete response');
        }

        return new DeviceAuthorization(
            deviceCode: $deviceCode,
            userCode: $userCode,
            verificationUri: $verificationUri,
            // Optional in the RFC; fall back to the plain URI so the caller always has
            // something to link to.
            verificationUriComplete: $this->str($data, 'verification_uri_complete') ?: $verificationUri,
            expiresIn: $this->int($data, 'expires_in', 600),
            interval: max(1, $this->int($data, 'interval', 5)),
        );
    }

    /**
     * Poll once for the token. Pending and slow-down come back as a result; the two
     * terminal failures throw, because there is nothing left to poll for.
     */
    public function pollDeviceToken(string $baseUrl, string $deviceCode): DevicePollResult
    {
        $response = $this->request('POST', $baseUrl, '/auth/app/token', [
            'body' => [
                'grant_type' => self::DEVICE_GRANT,
                'device_code' => $deviceCode,
                'client_id' => self::CLIENT_ID,
            ],
        ]);

        if ($response->getStatusCode() === 200) {
            $data = $this->decode($response);
            $token = $this->str($data, 'access_token');

            if ($token === '') {
                throw new FastmonApiException('fastmon token response carried no access_token');
            }

            $account = \is_array($data['account'] ?? null) ? $data['account'] : [];
            // Present once fastmon carries the approved organization through the grant.
            // Absent on an older instance, where the shop asks instead.
            $organization = \is_array($data['organization'] ?? null) ? $data['organization'] : [];

            return DevicePollResult::complete(
                $token,
                (string) ($account['email'] ?? ''),
                (string) ($account['name'] ?? ''),
                (string) ($organization['id'] ?? ''),
                (string) ($organization['name'] ?? ''),
            );
        }

        // RFC 8628 §3.5 puts the state in an `error` code on a 400. fastmon's own
        // envelope nests it under `error.code`, so both shapes are read.
        return match ($this->oauthError($response)) {
            'authorization_pending' => DevicePollResult::pending(),
            'slow_down' => DevicePollResult::slowDown(),
            'access_denied' => throw new FastmonApiException('The connection was declined in fastmon.'),
            'expired_token' => throw new FastmonApiException('The code expired before it was approved. Start again.'),
            default => $this->fail($response, 'fastmon token request failed'),
        };
    }

    /**
     * Who a token belongs to. Doubles as the cheapest way to find out whether a token
     * works at all, which is what the paste-a-token path uses it for.
     *
     * @return array{email: string, name: string}
     */
    public function account(string $baseUrl, string $token): array
    {
        $response = $this->request('GET', $baseUrl, '/account', ['auth_bearer' => $token]);

        if ($response->getStatusCode() !== 200) {
            $this->fail($response, 'fastmon account lookup failed');
        }

        $data = $this->decode($response);

        return [
            'email' => $this->str($data, 'email'),
            // Optional on the fastmon side, so an account without one is normal.
            'name' => $this->str($data, 'full_name'),
        ];
    }

    /**
     * The organizations this token may create applications in.
     *
     * @return list<array{id: string, name: string}>
     */
    public function organizations(string $baseUrl, string $token): array
    {
        $response = $this->request('GET', $baseUrl, '/organizations', ['auth_bearer' => $token]);

        if ($response->getStatusCode() !== 200) {
            $this->fail($response, 'fastmon organization list failed');
        }

        $out = [];

        foreach ($this->listData($response) as $org) {
            $out[] = [
                'id' => (string) ($org['id'] ?? ''),
                'name' => (string) ($org['name'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * The applications already present in an organization, so the merchant can attach
     * the shop to one instead of creating a duplicate.
     *
     * @return list<array{id: string, name: string, trackerId: string, pixelId: string, environment: string, siteCount: int}>
     */
    public function applications(string $baseUrl, string $token, string $organizationId): array
    {
        $response = $this->request(
            'GET',
            $baseUrl,
            '/organizations/' . rawurlencode($organizationId) . '/applications',
            ['auth_bearer' => $token]
        );

        if ($response->getStatusCode() !== 200) {
            $this->fail($response, 'fastmon application list failed');
        }

        $out = [];

        foreach ($this->listData($response) as $app) {
            $out[] = $this->application($app);
        }

        return $out;
    }

    /**
     * Create an application: one embed whose collection config covers every domain it
     * runs on. Which domain a beacon belongs to is resolved on arrival from the page
     * URL, so a shop with twelve sales channels still needs exactly one of these.
     *
     * @return array{id: string, name: string, trackerId: string, pixelId: string, environment: string, siteCount: int}
     */
    public function createApplication(
        string $baseUrl,
        string $token,
        string $organizationId,
        string $name,
        string $environment,
        string $preset,
    ): array {
        $response = $this->request(
            'POST',
            $baseUrl,
            '/organizations/' . rawurlencode($organizationId) . '/applications',
            [
                'auth_bearer' => $token,
                'json' => [
                    'name' => $name,
                    'environment' => $environment,
                    'preset' => $preset,
                    // Every sales-channel domain provisions itself on first beacon. This
                    // is the whole reason the plugin never enumerates sales channels.
                    'site_policy' => 'auto',
                    // fastmon ships a Shopware 6 body-class ruleset, so page types are
                    // classified without the plugin sending anything extra - and it keeps
                    // working on pages served from the full page cache.
                    'pagetype_ruleset' => 'shopware6',
                ],
            ]
        );

        $status = $response->getStatusCode();

        if ($status !== 200 && $status !== 201) {
            $this->fail($response, 'fastmon application creation failed');
        }

        $created = $this->application($this->decode($response));

        if ($created['trackerId'] === '') {
            throw new FastmonApiException('fastmon application creation returned no source_hash');
        }

        return $created;
    }

    /**
     * The domains fastmon has actually seen this application on.
     *
     * With `site_policy: auto` these appear on their own, the first time a visitor loads
     * a page on a domain - so the list is the honest answer to "is it collecting?", in a
     * way a green checkmark next to a tracker id is not. An empty list on a live shop
     * means the snippet is not reaching anyone.
     *
     * @return list<array{id: string, domain: string, name: string}>
     */
    public function applicationSites(string $baseUrl, string $token, string $applicationId): array
    {
        $response = $this->request(
            'GET',
            $baseUrl,
            '/applications/' . rawurlencode($applicationId) . '/sites',
            ['auth_bearer' => $token]
        );

        if ($response->getStatusCode() !== 200) {
            $this->fail($response, 'fastmon site list failed');
        }

        $out = [];

        foreach ($this->listData($response) as $site) {
            $out[] = [
                'id' => (string) ($site['id'] ?? ''),
                'domain' => (string) ($site['domain'] ?? ''),
                'name' => (string) ($site['name'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Switch where the served bundle posts its beacons.
     *
     * `relative` makes the endpoint a host-less `/c/<hash>`, resolved same-origin against
     * whatever domain the page runs on - which is what turns one application embedded
     * across every sales-channel domain into a first-party setup on each of them, with no
     * per-domain configuration.
     *
     * `collector_mode` and `collector_endpoint` are sent together because fastmon rejects
     * one without the other: they are an atomic pair, and a mode change that left a stale
     * endpoint behind would point every beacon at the wrong host.
     *
     * @return array{id: string, name: string, trackerId: string, pixelId: string, environment: string, siteCount: int}
     */
    public function setCollectorMode(
        string $baseUrl,
        string $token,
        string $applicationId,
        string $mode,
        ?string $endpoint = null,
    ): array {
        $response = $this->request(
            'PATCH',
            $baseUrl,
            '/applications/' . rawurlencode($applicationId),
            [
                'auth_bearer' => $token,
                'json' => [
                    'collector_mode' => $mode,
                    // Only `custom` carries one; `relative` and `default` must not, and
                    // fastmon rejects the combination rather than ignoring it.
                    'collector_endpoint' => $mode === 'custom' ? $endpoint : null,
                ],
            ]
        );

        if ($response->getStatusCode() !== 200) {
            $this->fail($response, 'fastmon collector mode change failed');
        }

        return $this->application($this->decode($response));
    }

    /**
     * Generate the application's proxy-trust secret, or rotate an existing one.
     *
     * The merchant's proxy presents it in `FM-Proxy-Key`, and only then does fastmon's
     * edge believe the `X-Forwarded-For` the proxy sends. Without it every beacon carries
     * the shop server's own address, so every visitor shares one country and one
     * pseudonymous identity - collected, plausible, and wrong.
     *
     * Rotation keeps the previous secret valid, so a proxy config can be updated without
     * a gap.
     *
     * @return string the secret, which fastmon returns exactly here and nowhere else
     */
    public function rotateProxySecret(string $baseUrl, string $token, string $applicationId): string
    {
        $response = $this->request(
            'POST',
            $baseUrl,
            '/applications/' . rawurlencode($applicationId) . '/proxy-secret',
            ['auth_bearer' => $token]
        );

        if ($response->getStatusCode() !== 200) {
            $this->fail($response, 'fastmon proxy secret generation failed');
        }

        $secret = $this->str($this->decode($response), 'proxy_secret');

        if ($secret === '') {
            // Happens when the token lacks `app:write`: fastmon then reports that a
            // secret exists rather than what it is, and a config built on an empty
            // value would fail silently at the edge.
            throw new FastmonApiException(
                'fastmon did not return the proxy secret. The connected token needs the app:write permission.'
            );
        }

        return $secret;
    }

    /**
     * Re-read one application, to confirm a stored id still exists and its hashes still
     * match what the storefront is emitting.
     *
     * @return array{id: string, name: string, trackerId: string, pixelId: string, environment: string, siteCount: int}
     */
    public function fetchApplication(string $baseUrl, string $token, string $applicationId): array
    {
        $response = $this->request(
            'GET',
            $baseUrl,
            '/applications/' . rawurlencode($applicationId),
            ['auth_bearer' => $token]
        );

        if ($response->getStatusCode() !== 200) {
            $this->fail($response, 'fastmon application lookup failed');
        }

        return $this->application($this->decode($response));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{id: string, name: string, trackerId: string, pixelId: string, environment: string, siteCount: int}
     */
    private function application(array $data): array
    {
        return [
            'id' => (string) ($data['id'] ?? ''),
            'name' => (string) ($data['name'] ?? ''),
            // fastmon's field names describe what they are on the wire; the plugin's
            // describe what they do in a template.
            'trackerId' => (string) ($data['source_hash'] ?? ''),
            'pixelId' => (string) ($data['collector_hash'] ?? ''),
            'environment' => (string) ($data['environment'] ?? ''),
            'siteCount' => (int) ($data['site_count'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function request(string $method, string $baseUrl, string $path, array $options): ResponseInterface
    {
        $options['headers'] = ($options['headers'] ?? []) + ['Accept' => 'application/json'];
        $options['timeout'] = self::TIMEOUT_SECONDS;

        try {
            $response = $this->httpClient->request(
                $method,
                rtrim($baseUrl, '/') . self::PATH_PREFIX . $path,
                $options
            );
            // Force the transport to complete here, so a connection failure surfaces as
            // our own exception rather than from whatever line first reads the response.
            $response->getStatusCode();

            return $response;
        } catch (HttpExceptionInterface $e) {
            throw new FastmonApiException('Could not reach fastmon: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        try {
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new FastmonApiException('fastmon returned a response that is not JSON', 0, $e);
        }

        return $data;
    }

    /**
     * The `data` array of a paginated list response.
     *
     * @return list<array<string, mixed>>
     */
    private function listData(ResponseInterface $response): array
    {
        $rows = $this->decode($response)['data'] ?? [];

        if (!\is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, static fn (mixed $row): bool => \is_array($row)));
    }

    /**
     * The OAuth error code of a failed token request, in either shape it can arrive in:
     * flat (`{"error": "authorization_pending"}`, RFC 6749 §5.2) or nested in fastmon's
     * own envelope (`{"error": {"code": "...", ...}}`).
     */
    private function oauthError(ResponseInterface $response): string
    {
        try {
            $error = $this->decode($response)['error'] ?? null;
        } catch (FastmonApiException) {
            return '';
        }

        if (\is_string($error)) {
            return $error;
        }

        return \is_array($error) ? (string) ($error['code'] ?? '') : '';
    }

    /**
     * Raise the right exception for a non-2xx response: unauthorized on 401 so callers
     * drop to the reconnect path, a plain API error otherwise. The detail comes from
     * fastmon's error envelope when there is one, with the `request_id` appended because
     * that is what support needs to find the request.
     */
    private function fail(ResponseInterface $response, string $context): never
    {
        $status = $response->getStatusCode();
        $error = [];

        try {
            $body = $this->decode($response);

            if (\is_array($body['error'] ?? null)) {
                $error = $body['error'];
            }
        } catch (FastmonApiException) {
            // Not JSON: fall back to the bare status below.
        }

        $code = (string) ($error['code'] ?? '');
        $message = (string) ($error['message'] ?? '');
        $requestId = (string) ($error['request_id'] ?? '');
        $details = \is_array($error['details'] ?? null) ? $error['details'] : [];

        $detail = ($code !== '' || $message !== '')
            ? trim($code . ($code !== '' && $message !== '' ? ': ' : '') . $message)
            : 'HTTP ' . $status;

        if ($requestId !== '') {
            $detail .= ' (request_id: ' . $requestId . ')';
        }

        // Codes the caller reacts to differently get their own type. fastmon's own
        // wording already says what happens next in each case, so it is passed through
        // rather than buried under our context prefix.
        //
        // Not a failure the merchant can retry their way out of: fastmon gates ingestion
        // behind an org review, so a new account connects fine and then waits. The token
        // stays valid throughout - it must not be discarded on the way past.
        if ($code === 'organization_not_approved') {
            throw new FastmonOrganizationNotApprovedException(
                $message !== '' ? $message : 'This fastmon organization has not been approved yet.'
            );
        }

        // The one case where the token works and the call still cannot: fastmon names the
        // missing permission, which is what lets the module say which scope to add rather
        // than just "forbidden".
        if ($code === 'permission_denied') {
            $permission = (string) ($details['permission'] ?? '');

            throw new FastmonPermissionDeniedException(
                $message !== '' ? $message : 'This fastmon token is missing a required permission.',
                $permission
            );
        }

        // The authorizing account is no longer a member of the organization the shop is
        // linked to. Nothing here can repair that: the connection has to be made again.
        if ($code === 'organization_not_found') {
            throw new FastmonUnauthorizedException(
                'The linked fastmon organization is no longer reachable with this connection. Please connect again.'
            );
        }

        if ($code === 'credential_expired') {
            throw new FastmonCredentialExpiredException(
                'The fastmon connection expired. Please connect again.'
            );
        }

        $full = $context . ': ' . $detail;

        throw $status === 401
            ? new FastmonUnauthorizedException($full)
            : new FastmonApiException($full);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function str(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function int(array $data, string $key, int $default): int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }
}
