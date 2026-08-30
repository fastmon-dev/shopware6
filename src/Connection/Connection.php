<?php declare(strict_types=1);

namespace Fastmon\Collector\Connection;

/**
 * The shop's link to fastmon: the token it authenticates with, who authorized it, and
 * the application the storefront is emitting for.
 *
 * All of it is shop-wide. One application covers every sales channel, so there is
 * nothing here that could sensibly differ between them.
 */
final class Connection
{
    public function __construct(
        public readonly string $token,
        public readonly string $accountEmail,
        public readonly string $accountName,
        public readonly string $organizationId,
        public readonly string $organizationName,
        public readonly string $applicationId,
        public readonly string $trackerId,
        public readonly string $pixelId,
    ) {
    }

    /** Whether a token is stored at all. Says nothing about whether it still works. */
    public function isConnected(): bool
    {
        return $this->token !== '';
    }

    /** Whether the storefront has what it needs to emit the snippets. */
    public function isProvisioned(): bool
    {
        return $this->trackerId !== '';
    }
}
