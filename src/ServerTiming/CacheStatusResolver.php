<?php declare(strict_types=1);

namespace Fastmon\Collector\ServerTiming;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The full-page-cache verdict for this request, in fastmon's status vocabulary.
 *
 * Both inputs come from Shopware's own decisions, recorded by HttpCacheStatusSubscriber
 * as they happen: the cache answered (`hit`), or the cache stored what the kernel
 * produced (`miss`). Nothing is inferred from headers - see that class for why
 * `Cache-Control` is actively misleading here.
 *
 * The three values are deliberately coarse. `hit` and `miss` are what a cache rate is
 * built from; `bypass` covers every response the cache was never going to serve - a
 * logged-in customer, a filled cart, a POST, an uncacheable route - and keeping those out
 * of `miss` is what stops the rate from looking terrible on a shop whose cache is working
 * perfectly.
 */
class CacheStatusResolver
{
    /** Set when Shopware's HTTP cache answered the request. */
    public const HIT_ATTRIBUTE = 'fastmon-collector-fpc-hit';

    /** Set when Shopware's HTTP cache stored the response the kernel produced. */
    public const STORED_ATTRIBUTE = 'fastmon-collector-fpc-stored';

    public const HIT = 'hit';
    public const MISS = 'miss';
    public const BYPASS = 'bypass';

    /**
     * Null when there is nothing meaningful to report: anything that is not a top-level
     * HTML document, which is the only thing fastmon reads a navigation entry for.
     */
    public function resolve(Request $request, Response $response): ?string
    {
        if (!$this->isDocument($response)) {
            return null;
        }

        if ($request->attributes->getBoolean(self::HIT_ATTRIBUTE)) {
            return self::HIT;
        }

        return $request->attributes->getBoolean(self::STORED_ATTRIBUTE)
            ? self::MISS
            : self::BYPASS;
    }

    /**
     * A top-level HTML document. Sub-resources carry no navigation entry, so a verdict on
     * one is read by nothing.
     *
     * Public because the same question decides more than the cache verdict: every
     * categorical entry the plugin emits is a statement about a page, and a page is what
     * this identifies.
     */
    public function isDocument(Response $response): bool
    {
        $contentType = $response->headers->get('Content-Type', '');

        return \is_string($contentType) && str_contains(mb_strtolower($contentType), 'text/html');
    }
}
