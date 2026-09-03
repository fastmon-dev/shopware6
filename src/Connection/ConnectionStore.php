<?php declare(strict_types=1);

namespace Fastmon\Collector\Connection;

use DateTimeImmutable;
use DateTimeInterface;
use Fastmon\Collector\Api\OAuthTokens;
use Fastmon\Collector\Connection\Storage\ConnectionCollection;
use Fastmon\Collector\Connection\Storage\ConnectionDefinition;
use Fastmon\Collector\Connection\Storage\ConnectionEntity;
use Fastmon\Collector\Service\ConfigResolver;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads and writes the fastmon connection.
 *
 * ## Where it lives
 *
 * In `fastmon_collector_connection`, one row, one column per field. It used to live in
 * `system_config`, and everything that was awkward about this class followed from that:
 * a store built for configuration memoises the whole of it once per request, tags it into
 * the page cache, keeps every value as a JSON-wrapped string, and hands the lot to anyone
 * who may read the system-config endpoint. None of that is wrong for a setting and all of
 * it is wrong for a token, so the connection has a table of its own and the fields are
 * typed. `ConnectionDefinition` carries the rest of that reasoning.
 *
 * Two values stay in `system_config` on purpose: `sourceHash` and `collectorHash`, the
 * pair the storefront templates render. Those are configuration in the full sense, they are read
 * on every page, and Shopware dropping the cached pages that carry the old hash when they
 * change is the point rather than a side effect to avoid.
 *
 * ## On storing tokens in the shop database
 *
 * In plain text, which is where every Shopware plugin keeps its API credentials, payment
 * providers included. Encrypting them would mean keeping a key in `.env`, next to the
 * database credentials that already grant access to the same rows: it would change who
 * can read a token from "anyone with the database" to "anyone with the database and the
 * application directory", which is the same person on every shop this plugin will run on.
 *
 * What did change with app connections is how much a stolen row is worth. The access
 * token expires in minutes and the refresh token rotates on every use, so a copy taken
 * from a backup stops working the moment the shop refreshes, and using it announces the
 * theft, because fastmon ends a connection whose refresh token is presented twice.
 *
 * None of it is a form field, and none of it is reachable through the API: the entity
 * admits the system scope only, and the plugin's own admin routes report whether a
 * connection exists and what it may do, never what it is.
 *
 * ## One method per group of fields
 *
 * That is the design rather than an accident: the fields written together are written in
 * one statement, and a caller assembling the writes itself is exactly the coupling this
 * class exists to remove.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
final class ConnectionStore
{
    /** The two values the storefront actually renders, and the only two left in `system_config`. */
    private const SOURCE_HASH = 'sourceHash';
    private const COLLECTOR_HASH = 'collectorHash';

    /**
     * The same two, for the one caller that cannot hold a store: an uninstall runs in a
     * container the plugin's own services have already left, so `FastmonCollector` reads
     * the names from here rather than keeping a second list of its own.
     *
     * @var string[]
     */
    public const RENDERED_KEYS = [self::SOURCE_HASH, self::COLLECTOR_HASH];

    /**
     * Everything that authenticates. Dropped together, whichever kind is stored.
     *
     * @var array<string, null>
     */
    private const NO_CREDENTIALS = [
        'accessToken' => null,
        'accessTokenExpiresAt' => null,
        'refreshToken' => null,
        'scopes' => null,
        'manualToken' => null,
    ];

    /**
     * Who and what the credential pointed at.
     *
     * @var array<string, null>
     */
    private const NO_IDENTITY = [
        'accountEmail' => null,
        'accountName' => null,
        'organizationId' => null,
        'organizationName' => null,
        'applicationId' => null,
    ];

    private readonly Context $context;

    /**
     * @param EntityRepository<ConnectionCollection> $repository
     */
    public function __construct(
        #[Autowire(service: 'fastmon_collector_connection.repository')]
        private readonly EntityRepository $repository,
        private readonly SystemConfigService $systemConfigService,
    ) {
        // The system scope, which is the only one the entity admits: this row is written
        // on the shop's behalf and never on a user's, and nobody is meant to reach it
        // through the API. `new Context(new SystemSource())` rather than
        // `Context::createDefaultContext()`, which core marks `@internal`.
        $this->context = new Context(new SystemSource());
    }

    public function load(): Connection
    {
        $row = $this->row();

        return new Connection(
            credentials: $this->credentialsOf($row),
            accountEmail: $this->str($row?->accountEmail),
            accountName: $this->str($row?->accountName),
            organizationId: $this->str($row?->organizationId),
            organizationName: $this->str($row?->organizationName),
            applicationId: $this->str($row?->applicationId),
            sourceHash: $this->rendered(self::SOURCE_HASH),
            collectorHash: $this->rendered(self::COLLECTOR_HASH),
        );
    }

    /**
     * Just the credential, for the hot path: every API call reads this and almost none of
     * them care who approved the connection.
     *
     * Always the row as the database has it. There is no per-request memo in front of the
     * DAL, which is what the refresh path depends on: the request that waited for the
     * lock has to see the token the winner stored, not the one it read before waiting.
     */
    public function credentials(): Credentials
    {
        return $this->credentialsOf($this->row());
    }

    /** Remember the registration, and the redirect URI it is only valid for. */
    public function saveClient(string $clientId, string $redirectUri): void
    {
        $this->write(['clientId' => $clientId, 'redirectUri' => $redirectUri]);
    }

    /**
     * Store a freshly issued pair.
     *
     * One row and one statement, so the pair cannot be half-written. That matters here
     * more than anywhere else: each refresh token works exactly once, and a shop left
     * holding a spent one would present it and be read as a thief. While these fields
     * were separate configuration keys, the order of the writes was what stood in for
     * this, and losing the successor between two of them was a real failure mode.
     *
     * Any pasted key goes at the same time: an app connection supersedes it, and leaving
     * one behind would mean a fallback quietly taking over the moment the grant ends.
     */
    public function saveTokens(OAuthTokens $tokens): void
    {
        $this->write([
            'refreshToken' => $tokens->refreshToken,
            'scopes' => $tokens->scope,
            'accessToken' => $tokens->accessToken,
            'accessTokenExpiresAt' => $this->at(time() + $tokens->expiresIn),
            'manualToken' => null,
        ]);
    }

    /**
     * Store a key the merchant created in the fastmon dashboard.
     *
     * Replaces an app connection rather than sitting beside it, so there is never a
     * question of which of two credentials a call was made with.
     */
    public function saveManualToken(string $token): void
    {
        // The key on the left: a union keeps the first occurrence of a key, and the
        // list of nulls carries one for `manualToken` too.
        $this->write(['manualToken' => $token] + self::NO_CREDENTIALS);
    }

    /** Who approved the connection. fastmon reports this once, at consent. */
    public function saveAccount(string $accountEmail, string $accountName): void
    {
        $this->write(['accountEmail' => $accountEmail, 'accountName' => $accountName]);
    }

    /**
     * Record the organization the connection is bound to.
     *
     * Separate from `saveApplication()` because it is known earlier and from a better
     * source: the token response names the organization the merchant approved on the
     * consent screen, so the shop can never end up reporting to a different one than the
     * one they saw.
     */
    public function saveOrganization(string $organizationId, string $organizationName): void
    {
        $this->write(['organizationId' => $organizationId, 'organizationName' => $organizationName]);
    }

    /**
     * Point the storefront at an application. `sourceHash` is what actually turns the
     * snippets on, so it is written last: a half-written link renders nothing rather than
     * a script tag with an empty hash.
     */
    public function saveApplication(string $organizationId, string $applicationId, string $sourceHash, string $collectorHash): void
    {
        $this->write(['organizationId' => $organizationId, 'applicationId' => $applicationId]);
        $this->saveRendered(self::COLLECTOR_HASH, $collectorHash);
        $this->saveRendered(self::SOURCE_HASH, $sourceHash);
    }

    /**
     * The authorization in flight, while the merchant is away at fastmon's consent
     * screen. One at a time, which is what a shop connecting to one account needs:
     * starting a second replaces the first, so an abandoned attempt cannot linger.
     */
    public function saveAuthorization(Authorization $authorization): void
    {
        $this->write([
            'authorizationState' => $authorization->state,
            'authorizationVerifier' => $authorization->verifier,
            'authorizationClientId' => $authorization->clientId,
            'authorizationRedirectUri' => $authorization->redirectUri,
            'authorizationExpiresAt' => $this->at($authorization->expiresAt),
        ]);
    }

    /** The attempt in flight, or null when this shop has none. */
    public function authorization(): ?Authorization
    {
        $row = $this->row();
        $state = $this->str($row?->authorizationState);
        $verifier = $this->str($row?->authorizationVerifier);

        if ($state === '' || $verifier === '') {
            return null;
        }

        return new Authorization(
            state: $state,
            verifier: $verifier,
            clientId: $this->str($row?->authorizationClientId),
            redirectUri: $this->str($row?->authorizationRedirectUri),
            expiresAt: $row?->authorizationExpiresAt?->getTimestamp() ?? 0,
        );
    }

    /** Called once the authorization ended, successfully or not. */
    public function clearAuthorization(): void
    {
        $this->write([
            'authorizationState' => null,
            'authorizationVerifier' => null,
            'authorizationClientId' => null,
            'authorizationRedirectUri' => null,
            'authorizationExpiresAt' => null,
        ]);
    }

    /**
     * Drop the credential and leave everything else standing.
     *
     * What a rejected connection needs: the linked application, its hashes and the
     * organization are still correct, and reconnecting for the same organization must not
     * cost the merchant the storefront snippets.
     */
    public function clearCredentials(): void
    {
        $this->write(self::NO_CREDENTIALS);
    }

    /**
     * Disconnect: the credential and everything it pointed at.
     *
     * The registration stays, because it is not a credential and re-using it is what
     * keeps this shop one entry in fastmon's connection list rather than a new one per
     * reconnect.
     */
    public function clear(): void
    {
        $this->write(self::NO_CREDENTIALS + self::NO_IDENTITY);
        $this->forgetRendered();
    }

    /** Uninstall: everything this store owns, registration included. */
    public function clearAll(): void
    {
        $this->repository->delete([['id' => ConnectionDefinition::ROW_ID]], $this->context);
        $this->forgetRendered();
    }

    private function row(): ?ConnectionEntity
    {
        // Through `getEntities()` rather than the result's own `first()`: on 6.8 the
        // search result stops extending the collection, and that call goes with it.
        $row = $this->repository
            ->search(new Criteria([ConnectionDefinition::ROW_ID]), $this->context)
            ->getEntities()
            ->first();

        return $row instanceof ConnectionEntity ? $row : null;
    }

    /**
     * @param array<string, string|DateTimeInterface|null> $data
     */
    private function write(array $data): void
    {
        // Upsert against the one fixed id: a shop has one connection, so there is no
        // row to look up before writing and no second row this could ever create.
        $this->repository->upsert([['id' => ConnectionDefinition::ROW_ID] + $data], $this->context);
    }

    private function credentialsOf(?ConnectionEntity $row): Credentials
    {
        return new Credentials(
            clientId: $this->str($row?->clientId),
            redirectUri: $this->str($row?->redirectUri),
            accessToken: $this->str($row?->accessToken),
            expiresAt: $row?->accessTokenExpiresAt?->getTimestamp() ?? 0,
            refreshToken: $this->str($row?->refreshToken),
            scopes: $this->str($row?->scopes),
            manualToken: $this->str($row?->manualToken),
        );
    }

    private function str(?string $value): string
    {
        return $value === null ? '' : trim($value);
    }

    private function at(int $timestamp): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $timestamp);
    }

    /**
     * One of the two rendered values, from `system_config`.
     *
     * Written loudly, which is what invalidates the cached pages still carrying the old
     * id. On 6.8 that becomes the wrong default and these two writes will have to say
     * `silent: false` out loud; the plugin does not claim 6.8 yet, and by then the flag
     * is a real parameter rather than something read out of `func_get_args()`.
     */
    private function rendered(string $key): string
    {
        $value = $this->systemConfigService->get(ConfigResolver::DOMAIN . $key);

        return \is_scalar($value) ? trim((string) $value) : '';
    }

    private function saveRendered(string $key, string $value): void
    {
        $this->systemConfigService->set(ConfigResolver::DOMAIN . $key, $value);
    }

    private function forgetRendered(): void
    {
        foreach (self::RENDERED_KEYS as $key) {
            $this->systemConfigService->delete(ConfigResolver::DOMAIN . $key);
        }
    }
}
