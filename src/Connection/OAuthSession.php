<?php declare(strict_types=1);

namespace Fastmon\Collector\Connection;

use Fastmon\Collector\Api\Pkce;
use Fastmon\Collector\Service\ConfigResolver;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The half of an authorization that stays on the shop while the merchant is away at
 * fastmon: the PKCE verifier, the `state`, and the exact parameters the code will have to
 * be redeemed with.
 *
 * The verifier never reaches the browser. It is the only thing that authenticates the
 * code exchange - this shop is a public OAuth client and has no secret - so an attacker
 * who intercepts the authorization code must not be able to pick the verifier up from a
 * page or a `sessionStorage` entry. The browser carries `state` and the code, both of
 * which are useless without it.
 *
 * `redirectUri` and `clientId` are stored rather than recomputed at exchange time,
 * because fastmon binds the code to exactly what the authorization request carried: if
 * the shop's own address changed in between, recomputing would produce a value that no
 * longer matches and a failure nobody could read.
 *
 * ## Why `system_config` and not the object cache
 *
 * Because a cache is allowed to forget, and this must not. The obvious home was
 * `cache.object`, and it fails in practice: a Shopware installation is free to back its
 * app cache with an array adapter (dockware's dev image does exactly that, so nothing
 * survives the request that wrote it) or with APCu, which is per-process - the callback
 * then lands on a different PHP-FPM worker and finds nothing. Both turn the connect flow
 * into an authorization that expires the instant it starts, on a shop where every other
 * part of the plugin works.
 *
 * `system_config` is the one store a plugin can count on: it is shared across workers, it
 * is where the resulting tokens go anyway, and it is written through the service the rest
 * of this plugin already uses. The entry carries its own expiry and is deleted the moment
 * the authorization ends, successfully or not.
 *
 * One authorization at a time, which is what a shop connecting to one account needs.
 * Starting a second one replaces the first, so an abandoned attempt cannot linger.
 */
final class OAuthSession
{
    public const KEY = ConfigResolver::DOMAIN . 'oauthSession';

    /**
     * State length in bytes before hex encoding. 16 bytes is 128 bits: not guessable, and
     * the value is worthless after half an hour anyway.
     */
    private const STATE_BYTES = 16;

    /**
     * How long the merchant has to finish consent. Generous, because it covers logging in
     * to fastmon and possibly a step-up on the way, and short enough that an abandoned
     * attempt does not sit in the configuration for a day.
     */
    private const LIFETIME_SECONDS = 1800;

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    /**
     * Begin an authorization.
     *
     * @return array{state: string, verifier: string} the state to send, and the verifier to hash into the challenge
     */
    public function start(string $clientId, string $redirectUri): array
    {
        $state = bin2hex(random_bytes(self::STATE_BYTES));
        $verifier = Pkce::verifier();

        $this->systemConfigService->set(self::KEY, json_encode([
            'state' => $state,
            'verifier' => $verifier,
            'clientId' => $clientId,
            'redirectUri' => $redirectUri,
            'expiresAt' => time() + self::LIFETIME_SECONDS,
        ], \JSON_THROW_ON_ERROR));

        return ['state' => $state, 'verifier' => $verifier];
    }

    /**
     * What the code has to be redeemed with, or null when the state is unknown, expired
     * or belongs to an attempt that already finished.
     *
     * @return array{verifier: string, clientId: string, redirectUri: string}|null
     */
    public function resolve(string $state): ?array
    {
        if (!$this->isWellFormed($state)) {
            return null;
        }

        $stored = $this->read();

        if ($stored === null || !$this->matches($stored, $state)) {
            return null;
        }

        $expiresAt = $stored['expiresAt'] ?? null;

        if (!\is_int($expiresAt) || $expiresAt < time()) {
            $this->abandon();

            return null;
        }

        return $this->attempt($stored);
    }

    /**
     * The stored attempt, or null when it is not one this shop could redeem a code with.
     *
     * @param array<mixed> $stored
     *
     * @return array{verifier: string, clientId: string, redirectUri: string}|null
     */
    private function attempt(array $stored): ?array
    {
        $verifier = $stored['verifier'] ?? null;
        $clientId = $stored['clientId'] ?? null;
        $redirectUri = $stored['redirectUri'] ?? null;

        if (!\is_string($verifier) || $verifier === '' || !\is_string($clientId) || !\is_string($redirectUri)) {
            return null;
        }

        return ['verifier' => $verifier, 'clientId' => $clientId, 'redirectUri' => $redirectUri];
    }

    /**
     * Called once the authorization ended, successfully or not.
     *
     * Only the attempt this state belongs to: a late callback from an abandoned attempt
     * must not wipe the one the merchant just started.
     */
    public function finish(string $state): void
    {
        $stored = $this->read();

        if ($stored !== null && $this->matches($stored, $state)) {
            $this->abandon();
        }
    }

    /** Drop any attempt in flight, whatever it is. Used when disconnecting. */
    public function abandon(): void
    {
        $this->systemConfigService->delete(self::KEY);
    }

    /**
     * @param array<mixed> $stored
     */
    private function matches(array $stored, string $state): bool
    {
        $storedState = $stored['state'] ?? null;

        // Constant time: `state` arrives over HTTP, and a timing oracle on it would be an
        // oracle on an authorization in flight.
        return \is_string($storedState) && hash_equals($storedState, $state);
    }

    /**
     * @return array<mixed>|null
     */
    private function read(): ?array
    {
        $raw = $this->systemConfigService->get(self::KEY);

        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * Checked before the value is compared: a state arrives over HTTP, and a malformed one
     * is not worth a lookup.
     */
    private function isWellFormed(string $state): bool
    {
        return preg_match('/^[0-9a-f]{' . (self::STATE_BYTES * 2) . '}$/', $state) === 1;
    }
}
