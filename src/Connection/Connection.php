<?php declare(strict_types=1);

namespace Fastmon\Collector\Connection;

/**
 * The shop's link to fastmon: what it authenticates with, who approved it, and the
 * application the storefront is emitting for.
 *
 * All of it is shop-wide. One application covers every sales channel, so there is
 * nothing here that could sensibly differ between them.
 */
final readonly class Connection
{
    public function __construct(
        public Credentials $credentials,
        /** Who approved the connection. Reported once, at consent; a refresh names nobody. */
        public string $accountEmail,
        public string $accountName,
        public string $organizationId,
        public string $organizationName,
        public string $applicationId,
        public string $sourceHash,
        public string $collectorHash,
    ) {
    }

    /** Whether anything is stored to authenticate with. Says nothing about it still working. */
    public function isConnected(): bool
    {
        return $this->credentials->isConnected();
    }

    /** Whether the storefront has what it needs to emit the snippets. */
    public function isProvisioned(): bool
    {
        return $this->sourceHash !== '';
    }
}
