<?php declare(strict_types=1);

namespace Fastmon\Collector\Collection;

/**
 * Where the storefront loads the tracker from and where the beacon goes.
 *
 * Mirrors fastmon's `CollectorMode` one to one, because the two have to agree: the
 * collector endpoint is baked into the bundle fastmon serves, while the `<script src>`
 * is written by this plugin's template. If they disagreed, the tracker would load from
 * one host and post to another - which is either a needless cross-origin request or, on
 * the domain that is wrong, nothing at all.
 *
 * `CUSTOM` and `RELATIVE` are both first-party in practice; they differ in whether the
 * host is pinned. `RELATIVE` fits a shop with several sales-channel domains, because one
 * embed then resolves against whichever domain the page is on. `CUSTOM` pins one host,
 * which is what a shop with a single domain or a dedicated measurement subdomain wants.
 */
enum CollectionMode: string
{
    /**
     * fastmon's shared collector. Third-party, works everywhere, needs no setup.
     *
     * Named FASTMON rather than DEFAULT: `case DEFAULT` is accepted by PHP 8.3 but trips
     * tooling that parses against the reserved word, and `CollectionMode::FASTMON` says
     * whose collector it is anyway. The wire value stays `default`, which is what fastmon
     * expects.
     */
    case FASTMON = 'default';

    /** One absolute host the merchant runs, e.g. `https://metrics.example.com`. */
    case CUSTOM = 'custom';

    /** Host-less `/c/<hash>`, resolved same-origin against every storefront domain. */
    case RELATIVE = 'relative';

    public static function fromConfigValue(mixed $value): self
    {
        return \is_string($value) ? (self::tryFrom($value) ?? self::FASTMON) : self::FASTMON;
    }

    /**
     * Whether switching to this mode depends on the merchant's own infrastructure, and
     * therefore has to be proven before it is switched on. Only the fastmon default
     * needs nothing.
     */
    public function needsProof(): bool
    {
        return $this !== self::FASTMON;
    }
}
