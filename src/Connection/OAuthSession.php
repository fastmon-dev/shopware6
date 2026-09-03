<?php declare(strict_types=1);

namespace Fastmon\Collector\Connection;

use Fastmon\Collector\Api\Pkce;

/**
 * The half of an authorization that stays on the shop while the merchant is away at
 * fastmon: the PKCE verifier, the `state`, and the exact parameters the code will have to
 * be redeemed with.
 *
 * The verifier never reaches the browser. It is the only thing that authenticates the
 * code exchange, because this shop is a public OAuth client and has no secret, so an
 * attacker who intercepts the authorization code must not be able to pick the verifier up
 * from a page or a `sessionStorage` entry. The browser carries `state` and the code, both
 * of which are useless without it.
 *
 * ## Why the database and not the object cache
 *
 * Because a cache is allowed to forget, and this must not. The obvious home was
 * `cache.object`, and it fails in practice: a Shopware installation is free to back its
 * app cache with an array adapter (dockware's dev image does exactly that, so nothing
 * survives the request that wrote it) or with APCu, which is per process, so the callback
 * lands on a different PHP-FPM worker and finds nothing. Both turn the connect flow into
 * an authorization that expires the instant it starts, on a shop where every other part
 * of the plugin works.
 *
 * The connection's own row is the one place a plugin can count on: shared across workers,
 * where the resulting tokens go anyway, and typed, so the attempt is five columns rather
 * than a JSON blob in a configuration value. The entry carries its own expiry and is
 * cleared the moment the authorization ends, successfully or not.
 */
final class OAuthSession
{
    /**
     * State length in bytes before hex encoding. 16 bytes is 128 bits: not guessable, and
     * the value is worthless after half an hour anyway.
     */
    private const STATE_BYTES = 16;

    /**
     * How long the merchant has to finish consent. Generous, because it covers logging in
     * to fastmon and possibly a step-up on the way, and short enough that an abandoned
     * attempt does not sit in the database for a day.
     */
    private const LIFETIME_SECONDS = 1800;

    public function __construct(
        private readonly ConnectionStore $store,
    ) {
    }

    /**
     * Begin an authorization.
     *
     * @return array{state: string, verifier: string} the state to send, and the verifier to hash into the challenge
     */
    public function start(string $clientId, string $redirectUri): array
    {
        $attempt = new Authorization(
            state: bin2hex(random_bytes(self::STATE_BYTES)),
            verifier: Pkce::verifier(),
            clientId: $clientId,
            // Stored rather than recomputed at exchange time, because fastmon binds the
            // code to exactly what the authorization request carried: if the shop's own
            // address changed in between, recomputing would produce a value that no
            // longer matches and a failure nobody could read.
            redirectUri: $redirectUri,
            expiresAt: time() + self::LIFETIME_SECONDS,
        );

        $this->store->saveAuthorization($attempt);

        return ['state' => $attempt->state, 'verifier' => $attempt->verifier];
    }

    /**
     * What the code has to be redeemed with, or null when the state is unknown, expired
     * or belongs to an attempt that already finished.
     */
    public function resolve(string $state): ?Authorization
    {
        if (!$this->isWellFormed($state)) {
            return null;
        }

        $attempt = $this->store->authorization();

        if ($attempt === null || !$this->matches($attempt, $state)) {
            return null;
        }

        if ($attempt->isExpired()) {
            $this->abandon();

            return null;
        }

        return $attempt;
    }

    /**
     * Called once the authorization ended, successfully or not.
     *
     * Only the attempt this state belongs to: a late callback from an abandoned attempt
     * must not wipe the one the merchant just started.
     */
    public function finish(string $state): void
    {
        $attempt = $this->store->authorization();

        if ($attempt !== null && $this->matches($attempt, $state)) {
            $this->abandon();
        }
    }

    /** Drop any attempt in flight, whatever it is. Used when disconnecting. */
    public function abandon(): void
    {
        $this->store->clearAuthorization();
    }

    private function matches(Authorization $attempt, string $state): bool
    {
        // Constant time: `state` arrives over HTTP, and a timing oracle on it would be an
        // oracle on an authorization in flight.
        return hash_equals($attempt->state, $state);
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
