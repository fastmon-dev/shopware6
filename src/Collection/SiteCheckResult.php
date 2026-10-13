<?php declare(strict_types=1);

namespace Fastmon\Collector\Collection;

/**
 * What the page probe found on one storefront domain, and what became of it in fastmon.
 *
 * Two halves. The probe fills the first: whether a page on this domain carries this
 * application's snippet. `SiteRegistrar` fills the second: a domain that carries the
 * snippet is registered as a Site of the application, and `site` says how that went. A
 * domain without the snippet is never registered, because fastmon would then wait for
 * beacons that cannot come.
 *
 * Reason codes rather than sentences, for the same reason as `DomainCheckResult`.
 *
 * @internal produced by EndpointChecker and SiteRegistrar
 */
final readonly class SiteCheckResult
{
    /** The page answered with a status instead of a page. `detail` is the status. */
    public const REASON_PAGE_STATUS = 'page_status';
    /** The page could not be fetched at all. `detail` is the transport error. */
    public const REASON_PAGE_UNREACHABLE = 'page_unreachable';
    /** A page came back, and this application's snippet is not in it. */
    public const REASON_PAGE_NO_SNIPPET = 'page_no_snippet';

    /** Registered by this check. */
    public const SITE_CREATED = 'created';
    /** fastmon had it already. */
    public const SITE_EXISTING = 'existing';
    /** fastmon refused it. `siteDetail` is fastmon's reason. */
    public const SITE_FAILED = 'failed';

    public function __construct(
        /** The hostname, which is what a Site is keyed by. */
        public string $domain,
        /** The page that was fetched: the sales channel's URL, path included. */
        public string $url,
        public bool $snippetOk,
        /** One of the REASON_* codes; empty when the snippet was found. */
        public string $reason = '',
        /** Whatever the reason needs to be concrete: a status code, an error text. */
        public string $detail = '',
        /** One of the SITE_* codes; empty when nothing was registered. */
        public string $site = '',
        public string $siteDetail = '',
    ) {
    }

    public function withSite(string $site, string $detail = ''): self
    {
        return new self($this->domain, $this->url, $this->snippetOk, $this->reason, $this->detail, $site, $detail);
    }

    /**
     * @return array{domain: string, url: string, snippetOk: bool, reason: string, detail: string, site: string, siteDetail: string}
     */
    public function toArray(): array
    {
        return [
            'domain' => $this->domain,
            'url' => $this->url,
            'snippetOk' => $this->snippetOk,
            'reason' => $this->reason,
            'detail' => $this->detail,
            'site' => $this->site,
            'siteDetail' => $this->siteDetail,
        ];
    }
}
