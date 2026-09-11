<?php declare(strict_types=1);

namespace Fastmon\Collector\Connection;

/**
 * An authorization in flight: what the shop has to remember while the merchant is away
 * at fastmon's consent screen, and what the code will have to be redeemed with.
 *
 * `clientId` and `redirectUri` are copies taken when the attempt started, not references
 * to the registration: fastmon binds the code to exactly what the authorization request
 * carried, and if the shop's address changed in between, the registration would say
 * something the code no longer matches.
 */
final readonly class Authorization
{
    public function __construct(
        public string $state,
        /** The PKCE verifier. Never leaves the shop; the only thing that authenticates the exchange. */
        public string $verifier,
        public string $clientId,
        public string $redirectUri,
        /** Unix seconds. */
        public int $expiresAt,
    ) {
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < time();
    }
}
