<?php declare(strict_types=1);

namespace Fastmon\Collector\ServerTiming;

use Symfony\Contracts\Service\ResetInterface;

/**
 * What the storefront controller observed, carried to where the header is written.
 *
 * ## Why this is a service and not a request attribute
 *
 * Because the two ends do not share a Request. Shopware's HTTP cache forwards to the
 * inner kernel through Symfony's `SubRequestHandler`, which hands it a *duplicate*: the
 * request the controller sees is a different object from the one `HttpCacheKernel` still
 * holds when it dispatches `BeforeSendResponseEvent`. Anything written onto the inner
 * request is simply gone by then - silently, which is how it was found: the header came
 * out complete except for the two entries that came from inside.
 *
 * The cache verdict escapes that trap because `CacheStore` is called with the *outer*
 * request, which is why it works as an attribute and this does not.
 *
 * A plain service crosses the boundary because both kernels run in the same process.
 * `reset()` is what keeps that honest in a long-running one: without it a request that
 * renders nothing would report the previous request's numbers.
 *
 * ## One page, many renders
 *
 * A storefront page dispatches `StorefrontRenderEvent` several times: the page template,
 * then every pagelet it pulls in - `frontend.header`, `frontend.footer` and whatever a
 * theme adds. Those arrive as separate passes through the inner kernel, because Symfony's
 * HTTP cache forwards ESI fragments as main requests, so per-request state is no defence
 * against them: it is torn down and rebuilt between the page and its own footer.
 *
 * What separates them is that only a page route maps to a page type. So the first pass
 * that produces one owns the request, and everything after it is ignored - which is also
 * what keeps the render time from being the footer's.
 */
final class RequestInsights implements ResetInterface
{
    /** Wall time spent rendering the template, in milliseconds. */
    private ?float $renderMilliseconds = null;

    /** The fastmon page type of the dispatched route. */
    private ?string $pageType = null;

    /** Whether a customer was authenticated - a guest checkout is not a login. */
    private ?bool $loggedIn = null;

    /** microtime(true) when the page template started rendering; 0 when none has. */
    private float $renderStart = 0.0;

    /**
     * Claim this request for a page. Ignored once a page already claimed it, which is
     * what keeps a pagelet from overwriting the page that contains it.
     *
     * @return bool whether this call took ownership
     */
    public function beginPage(string $pageType, bool $loggedIn): bool
    {
        if ($this->pageType !== null) {
            return false;
        }

        $this->pageType = $pageType;
        $this->loggedIn = $loggedIn;
        $this->renderStart = microtime(true);

        return true;
    }

    /**
     * Close the render measurement. Only the first call counts, for the same reason
     * `beginPage()` only accepts the first page.
     */
    public function endRender(): void
    {
        if ($this->renderStart > 0.0 && $this->renderMilliseconds === null) {
            $this->renderMilliseconds = (microtime(true) - $this->renderStart) * 1000;
        }
    }

    public function renderMilliseconds(): ?float
    {
        return $this->renderMilliseconds;
    }

    public function pageType(): ?string
    {
        return $this->pageType;
    }

    public function loggedIn(): ?bool
    {
        return $this->loggedIn;
    }

    public function reset(): void
    {
        $this->renderMilliseconds = null;
        $this->pageType = null;
        $this->loggedIn = null;
        $this->renderStart = 0.0;
    }
}
