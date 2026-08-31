<?php declare(strict_types=1);

namespace Fastmon\Collector\Connection;

/**
 * What the shop authenticates to fastmon with.
 *
 * Two shapes, and the difference is visible here rather than in every caller. An **app
 * connection** is the normal one: a `client_id` this installation registered for itself,
 * a short-lived access token, and a refresh token that rotates on every use. A **pasted
 * API key** is the fallback for a shop that cannot run the redirect - it never expires,
 * never refreshes, and is the reason `token()` is not simply "the access token".
 *
 * The key is separate from `Connection` because it has a different lifetime: the identity
 * and the linked application survive a token that was rotated, revoked or replaced, and
 * reconnecting must not unpublish the storefront snippets.
 */
final readonly class Credentials
{
    public function __construct(
        /** This installation's own OAuth client. Public by design; not a secret. */
        public string $clientId,
        /** The exact redirect URI the client was registered with; a change means re-registering. */
        public string $redirectUri,
        public string $accessToken,
        /** Unix seconds. 0 when nothing was issued, or when the shop pasted a key. */
        public int $expiresAt,
        public string $refreshToken,
        /** What was actually granted at consent, space separated - never what was asked for. */
        public string $scopes,
        /** A key pasted from the fastmon dashboard, for a shop that cannot run the redirect. */
        public string $manualToken,
    ) {
    }

    /** Whether anything at all is stored to authenticate with. */
    public function isConnected(): bool
    {
        return $this->refreshToken !== '' || $this->accessToken !== '' || $this->manualToken !== '';
    }

    /** Whether this is an app connection, which is the only kind that can renew itself. */
    public function isAppConnection(): bool
    {
        return $this->refreshToken !== '';
    }

    /**
     * Whether the stored access token is worth using.
     *
     * The skew is what keeps a call from being made with a token that expires while it is
     * in flight: fastmon issues them for 15 minutes, so refreshing a minute early costs
     * nothing and removes the whole class of "it worked when we checked".
     */
    public function hasFreshAccessToken(int $skewSeconds): bool
    {
        return $this->accessToken !== '' && $this->expiresAt > time() + $skewSeconds;
    }
}
