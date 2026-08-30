<?php declare(strict_types=1);

namespace Fastmon\Collector\Connection;

/**
 * The shop's link to fastmon: the token it authenticates with, who authorized it, and
 * the application the storefront is emitting for.
 *
 * All of it is shop-wide. One application covers every sales channel, so there is
 * nothing here that could sensibly differ between them.
 */
final readonly class Connection
{
    public function __construct(
        public string $token,
        public string $accountEmail,
        public string $accountName,
        public string $organizationId,
        public string $organizationName,
        public string $applicationId,
        public string $trackerId,
        public string $pixelId,
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
