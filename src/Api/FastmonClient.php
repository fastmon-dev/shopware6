<?php declare(strict_types=1);

namespace Fastmon\Collector\Api;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Talks to the fastmon API: organizations, applications, sites, and the settings that
 * decide where a beacon goes.
 *
 * Everything here authenticates with a bearer token and knows nothing about where that
 * token came from. Getting one is `FastmonOAuthClient`'s job, and the two are separate
 * because they speak different protocols against the same server - JSON bodies and
 * fastmon's error envelope here, form-encoded bodies and OAuth error codes there.
 *
 * Callers do not pass a token they read themselves. They go through
 * `AccessTokenProvider::call()`, which hands over one that is fresh and retries once if
 * fastmon rejects it anyway - an access token lives fifteen minutes, and the panel must
 * not show a merchant an error for that.
 *
 * The whole resource surface in one client, deliberately: one place to read what the
 * plugin asks fastmon.
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 */
final class FastmonClient
{
    use ReadsJsonResponses;

    /**
     * Every resource route lives under this prefix.
     *
     * fastmon's own notes describe the API as served unprefixed, with `/v1` kept alive
     * only as a legacy alias. That is where it is going, not where production is: the
     * unprefixed form answers 404 on api.fastmon.eu today while the `/v1` one answers,
     * so the newer spelling is the one that does not exist yet.
     *
     * `/v1` is the right choice permanently rather than a stopgap, because the prefix
     * stays valid after the unprefixed routes ship - a plugin in the wild cannot be
     * redeployed in step with the backend, and a prefix that works before and after is
     * worth more than one that is merely newer.
     *
     * The OAuth endpoints are not reached this way at all: they are read from the
     * discovery document, which names them absolutely - see `FastmonOAuthClient`.
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
                'id' => $this->str($org, 'id'),
                'name' => $this->str($org, 'name'),
            ];
        }

        return $out;
    }

    /**
     * The applications already present in an organization, so the merchant can attach
     * the shop to one instead of creating a duplicate.
     *
     * @return list<array{id: string, name: string, sourceHash: string, collectorHash: string, environment: string, siteCount: int, collectorMode: string, collectorEndpoint: string}>
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
     * @return array{id: string, name: string, sourceHash: string, collectorHash: string, environment: string, siteCount: int, collectorMode: string, collectorEndpoint: string}
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

        if ($created['sourceHash'] === '') {
            throw new FastmonApiException('fastmon application creation returned no source_hash');
        }

        return $created;
    }

    /**
     * The domains fastmon has actually seen this application on.
     *
     * With `site_policy: auto` these appear on their own, the first time a visitor loads
     * a page on a domain - so the list is the honest answer to "is it collecting?", in a
     * way a green checkmark next to a source_hash is not. An empty list on a live shop
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
                'id' => $this->str($site, 'id'),
                'domain' => $this->str($site, 'domain'),
                'name' => $this->str($site, 'name'),
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
     * @return array{id: string, name: string, sourceHash: string, collectorHash: string, environment: string, siteCount: int, collectorMode: string, collectorEndpoint: string}
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
     * @return array{id: string, name: string, sourceHash: string, collectorHash: string, environment: string, siteCount: int, collectorMode: string, collectorEndpoint: string}
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
     * @param array<mixed> $data
     *
     * @return array{id: string, name: string, sourceHash: string, collectorHash: string, environment: string, siteCount: int, collectorMode: string, collectorEndpoint: string}
     */
    private function application(array $data): array
    {
        return [
            'id' => $this->str($data, 'id'),
            'name' => $this->str($data, 'name'),
            // fastmon's field names describe what they are on the wire; the plugin's
            // describe what they do in a template.
            'sourceHash' => $this->str($data, 'source_hash'),
            'collectorHash' => $this->str($data, 'collector_hash'),
            'environment' => $this->str($data, 'environment'),
            'siteCount' => $this->int($data, 'site_count', 0),
            // Where fastmon currently sends the beacon. The shop mirrors it rather than
            // assuming its own stored value is still the truth: the endpoint is baked
            // into the bundle fastmon serves, so a mode changed in the dashboard has
            // already taken effect in every browser.
            'collectorMode' => $this->str($data, 'collector_mode'),
            // Null unless the mode is `custom`, which `str()` reads as an empty string.
            'collectorEndpoint' => $this->str($data, 'collector_endpoint'),
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function request(string $method, string $baseUrl, string $path, array $options): ResponseInterface
    {
        $headers = $options['headers'] ?? [];
        $options['headers'] = (\is_array($headers) ? $headers : []) + ['Accept' => 'application/json'];
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
     * The `data` array of a paginated list response.
     *
     * @return list<array<mixed>>
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
     * Raise the right exception for a non-2xx response: unauthorized on 401 so callers
     * drop to the reconnect path, a plain API error otherwise. The detail comes from
     * fastmon's error envelope when there is one, with the `request_id` appended because
     * that is what support needs to find the request.
 *
 * Maps fastmon's error envelope onto the exception hierarchy; the cases are the API contract, listed once.
 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
 * @SuppressWarnings("PHPMD.NPathComplexity")
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

        $code = $this->str($error, 'code');
        $message = $this->str($error, 'message');
        $requestId = $this->str($error, 'request_id');
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
            $permission = $this->str($details, 'permission');

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
}
