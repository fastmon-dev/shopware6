<?php declare(strict_types=1);

namespace Fastmon\Collector\Subscriber;

use Fastmon\Collector\Dto\ServerTimingConfig;
use Fastmon\Collector\ServerTiming\CacheStatusResolver;
use Fastmon\Collector\ServerTiming\LayerMetricsProviderInterface;
use Fastmon\Collector\ServerTiming\RequestInsights;
use Fastmon\Collector\ServerTiming\ServerIdentity;
use Fastmon\Collector\ServerTiming\ServerTimingHeaderBuilder;
use Fastmon\Collector\ServerTiming\ServerTimingResponseWriter;
use Fastmon\Collector\Service\ConfigResolver;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Event\BeforeSendResponseEvent;
use Shopware\Core\PlatformRequest;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Publishes what this request spent where as a `Server-Timing` header, so fastmon can
 * correlate backend phases with the Web Vitals of the very same pageview.
 *
 * Independent of everything else in the plugin: it needs no fastmon account, sends
 * nothing anywhere, and only annotates a response that was going out regardless. A shop
 * that never connects an account still gets a useful header in devtools.
 *
 * ## Why `BeforeSendResponseEvent` and not `kernel.response`
 *
 * `kernel.response` is the obvious place and it is the wrong one, because Shopware's
 * HTTP cache sits *outside* the kernel. On a full-page-cache hit the inner kernel never
 * runs, so a `kernel.response` listener never fires - and the response that goes out is
 * the stored one, carrying the header from whichever request populated the cache. The
 * browser would receive a completely plausible set of database and render timings
 * belonging to a different request, on every hit, for as long as the cache entry lives.
 * On a warm shop that is the overwhelming majority of pageviews.
 *
 * `BeforeSendResponseEvent` is dispatched by `HttpCacheKernel::handle()` on every main
 * request, hit and miss alike - its own docblock says "This event is also called on
 * cached responses" - and it runs *after* the response has been written to the cache.
 * Writing here therefore gets two things at once: the header reflects the request that
 * is actually being answered, and our entries never end up inside the cached copy.
 *
 * `ServerTimingResponseWriter` still strips stale `fm-*` entries on the way in, because
 * an *external* cache (Varnish, nginx, a CDN) stores whatever we sent and hands it back
 * on its own hits, where none of the above applies.
 *
 * ## Exactly one write point
 *
 * There is deliberately no `kernel.response` listener alongside this one. `http_kernel`
 * is decorated by `HttpCacheKernel` unconditionally - the decoration is plain, and the
 * compiler pass only injects options into it - so every web request reaches the event
 * above and a second listener would have nothing left to cover.
 *
 * It would, however, break the header. Our entries carry the layer names the profiler
 * reports (`rdbms`, `redis`, …), which is what lets fastmon promote them into its
 * columns without a translation table; but those names are not in the `fm-` namespace,
 * so the writer cannot tell one of ours from another party's and leaves them alone.
 * Writing twice therefore appends a second set of layers rather than replacing the
 * first, and the summed columns - kv, http, search - would count both.
 */
#[WithMonologChannel('fastmon_collector')]
final class ServerTimingSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ConfigResolver $configResolver,
        private readonly LayerMetricsProviderInterface $metricsProvider,
        private readonly ServerTimingHeaderBuilder $headerBuilder,
        private readonly ServerTimingResponseWriter $responseWriter,
        private readonly CacheStatusResolver $cacheStatusResolver,
        private readonly RequestInsights $insights,
        private readonly ServerIdentity $serverIdentity,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Outside the HTTP cache, after the response was stored. Very low priority
            // so the wall time includes what other response listeners added.
            BeforeSendResponseEvent::class => ['onBeforeSendResponse', -10000],
        ];
    }

    public function onBeforeSendResponse(BeforeSendResponseEvent $event): void
    {
        $request = $event->getRequest();

        // ESI sub-requests are handled by the same kernel method. Their headers are
        // discarded when the fragment is embedded, so writing one is pure waste.
        if ($request->attributes->has('_sw_esi')) {
            return;
        }

        $this->apply($request, $event->getResponse());
    }

    private function apply(Request $request, Response $response): void
    {
        try {
            $salesChannelId = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID);

            // Admin and API requests carry no sales channel and fall back to the global
            // setting, which is what makes the header usable for backend debugging too.
            $config = $this->configResolver->serverTiming(
                \is_string($salesChannelId) ? $salesChannelId : null
            );

            if (!$config->enabled) {
                return;
            }

            $own = $this->ownEntries($request, $response, $config);

            // Checked after the config but before any measurement: on a host with no
            // profiler this is the whole cost of having the feature installed. What we
            // measure ourselves needs no profiler and is worth publishing on its own.
            $metrics = $this->metricsProvider->isAvailable()
                ? $this->metricsProvider->metrics()
                : [];

            if ($metrics === [] && $own === []) {
                return;
            }

            $header = $this->headerBuilder->build($metrics, $own);

            if ($header === '') {
                return;
            }

            $this->responseWriter->write($response, $header);
        } catch (\Throwable $e) {
            // A monitoring header is never worth a broken response.
            $this->logger->warning('fastmon: could not add the Server-Timing header', ['exception' => $e]);
        }
    }

    /**
     * What this plugin knows about the request without any profiler.
     *
     * All of it is `fm-` prefixed, and that is not cosmetics: the writer recognises our
     * entries by that prefix, so an entry without it would survive on a response served
     * from an external cache and report a previous request's values. It also keeps them
     * clear of the collector's budget for names it does not recognise.
     *
     * @return list<array{0: string, 1: float|null, 2: string|null}>
     *
     * One guarded entry per value, a table rather than a tangle. Splitting it would
     * spread the rules for one header over six methods.
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     * @SuppressWarnings("PHPMD.NPathComplexity")
     */
    private function ownEntries(Request $request, Response $response, ServerTimingConfig $config): array
    {
        $own = [];

        // Categorical entries describe a page, and only the document carries a navigation
        // entry to read one from. Durations still go out on every response.
        $isDocument = $this->cacheStatusResolver->isDocument($response);

        if ($isDocument) {
            $status = $this->cacheStatusResolver->resolve($request, $response);

            if ($status !== null) {
                // Leads: on a hit it is the only entry that explains the numbers next to it.
                $own[] = [ServerTimingHeaderBuilder::CACHE_METRIC, null, $status];
            }

            // Seconds. Gated on the hit, not on the header: Symfony sets `Age` on a miss
            // too, derived from the Date header.
            $age = $response->headers->get('Age');

            if ($status === CacheStatusResolver::HIT && is_numeric($age)) {
                $own[] = ['fm-cacheage', (float) $age, null];
            }

            // Opt-in: which machine answered is a fact about the merchant's
            // infrastructure, and only a cluster has a use for it.
            $server = $config->reportHost ? $this->serverIdentity->name() : '';

            if ($server !== '') {
                $own[] = ['fm-host', null, $server];
            }
        }

        $total = $this->totalMilliseconds($request);

        if ($total !== null) {
            $own[] = [ServerTimingHeaderBuilder::TOTAL_METRIC, $total, null];
        }

        // Absent on a cache hit: a zero would be a real-looking number in the column.
        $render = $this->insights->renderMilliseconds();

        if ($render !== null) {
            $own[] = ['fm-render', $render, null];
        }

        if ($isDocument) {
            $pageType = $this->insights->pageType();

            if ($pageType !== null && $pageType !== '') {
                $own[] = ['fm-pagetype', null, $pageType];
            }

            // Opt-in, unlike everything else here: a visitor attribute in a header that
            // is collected in every privacy mode is the merchant's decision to make.
            $loggedIn = $config->reportLoggedIn ? $this->insights->loggedIn() : null;

            if ($loggedIn !== null) {
                $own[] = ['fm-loggedin', null, $loggedIn ? 'yes' : 'no'];
            }
        }

        return $own;
    }

    /**
     * Wall time of this PHP request so far, measured from the moment the web server
     * handed it over. It gives the layer numbers something to be a share of, and on a
     * cache hit it is the entire story.
     */
    private function totalMilliseconds(Request $request): ?float
    {
        $start = $request->server->get('REQUEST_TIME_FLOAT');

        if (!is_numeric($start) || (float) $start <= 0.0) {
            return null;
        }

        return (microtime(true) - (float) $start) * 1000;
    }
}
