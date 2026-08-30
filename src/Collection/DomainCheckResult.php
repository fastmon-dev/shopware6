<?php declare(strict_types=1);

namespace Fastmon\Collector\Collection;

/**
 * What the endpoint probe found on one origin.
 *
 * Carries a reason **code** rather than a sentence. Everything else the merchant reads in
 * this plugin comes from the snippet files and is translated; a message assembled in PHP
 * would be the one English line in a German administration, and it would say "HTTP 404"
 * where the reader needs to know what that means for them.
 *
 * @internal produced by EndpointChecker
 */
final readonly class DomainCheckResult
{
    /** `/s/` answered, but with a status instead of the bundle. `detail` is the status. */
    public const REASON_SCRIPT_STATUS = 'script_status';

    /** Something answered `/s/`, but it was not this application's fastmon bundle. */
    public const REASON_SCRIPT_FOREIGN = 'script_foreign';

    /** `/s/` could not be reached at all. `detail` is the transport error. */
    public const REASON_SCRIPT_UNREACHABLE = 'script_unreachable';

    /** `/c/` answered, but not with what fastmon's collector returns. */
    public const REASON_COLLECTOR_STATUS = 'collector_status';

    /** `/c/` could not be reached at all. `detail` is the transport error. */
    public const REASON_COLLECTOR_UNREACHABLE = 'collector_unreachable';

    public function __construct(
        public string $domain,
        public bool $scriptOk,
        public bool $collectorOk,
        /** One of the REASON_* codes; empty when both probes passed. */
        public string $reason = '',
        /** Whatever the reason needs to be concrete: a status code, an error text. */
        public string $detail = '',
    ) {
    }

    /**
     * Both paths have to work. The script alone is useless - the tracker would load and
     * then post into a 404 - and the collector alone is worse, because an ad blocker that
     * stops the script means nothing gets sent at all.
     */
    public function isReady(): bool
    {
        return $this->scriptOk && $this->collectorOk;
    }

    /**
     * @return array{domain: string, scriptOk: bool, collectorOk: bool, ready: bool, reason: string, detail: string}
     */
    public function toArray(): array
    {
        return [
            'domain' => $this->domain,
            'scriptOk' => $this->scriptOk,
            'collectorOk' => $this->collectorOk,
            'ready' => $this->isReady(),
            'reason' => $this->reason,
            'detail' => $this->detail,
        ];
    }
}
