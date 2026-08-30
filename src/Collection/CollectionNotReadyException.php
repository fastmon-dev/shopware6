<?php declare(strict_types=1);

namespace Fastmon\Collector\Collection;

/**
 * A mode was applied that the origins do not support.
 *
 * Carries the probe results rather than a sentence, so the administration can say what
 * went wrong in the reader's language and per origin. Normally unreachable - the apply
 * button only unlocks after a green check - but the setup can break in between, and that
 * is exactly the moment a merchant needs to be told which domain stopped answering
 * instead of a generic failure.
 */
class CollectionNotReadyException extends \RuntimeException
{
    /**
     * @param list<DomainCheckResult> $results
     */
    public function __construct(
        public readonly array $results,
    ) {
        parent::__construct('The chosen collection mode is not ready on every origin.');
    }

    /**
     * @return list<array{domain: string, scriptOk: bool, collectorOk: bool, ready: bool, reason: string, detail: string}>
     */
    public function toArray(): array
    {
        return array_map(static fn (DomainCheckResult $r): array => $r->toArray(), $this->results);
    }
}
