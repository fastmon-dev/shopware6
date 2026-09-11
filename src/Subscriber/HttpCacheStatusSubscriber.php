<?php declare(strict_types=1);

namespace Fastmon\Collector\Subscriber;

use Fastmon\Collector\ServerTiming\CacheStatusResolver;
use Shopware\Core\Framework\Adapter\Cache\Event\HttpCacheHitEvent;
use Shopware\Core\Framework\Adapter\Cache\Event\HttpCacheStoreEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Records what Shopware's HTTP cache did with this request.
 *
 * Both verdicts have to be captured as they happen, because neither is readable
 * afterwards: a stored response carries no marker of where it came from, and a miss
 * looks identical whether it was stored or not. Why a hit is invisible to a listener in
 * the kernel at all is at `ServerTimingSubscriber`.
 *
 * ## Why not read it off Cache-Control
 *
 * Because it says the opposite of what it looks like. Shopware serves a perfectly
 * cacheable storefront page with `Cache-Control: no-cache, private` - that header governs
 * the *browser*, and Shopware wants no shared downstream cache holding a page whose
 * context it controls. Its own reverse-proxy cache stores it regardless. Reading intent
 * out of that header therefore reports `bypass` for every page the cache is actually
 * working on, and `miss` would never appear at all.
 *
 * `HttpCacheStoreEvent` is dispatched at the end of `CacheStore::write()`, past every
 * early return for a non-cacheable key or maintenance mode, so it fires exactly when a
 * response really was stored. Taking both signals from Shopware's own decisions leaves
 * nothing to guess.
 *
 * The flags ride on the request rather than on this service: `CacheStore` and
 * `HttpCacheKernel::handle()` see the same Request instance, so they arrive - and a
 * request-scoped flag cannot leak into the next request the way a property on a
 * container service would in a long-running process.
 */
final class HttpCacheStatusSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            HttpCacheHitEvent::class => 'onCacheHit',
            HttpCacheStoreEvent::class => 'onCacheStore',
        ];
    }

    public function onCacheHit(HttpCacheHitEvent $event): void
    {
        $event->request->attributes->set(CacheStatusResolver::HIT_ATTRIBUTE, true);
    }

    public function onCacheStore(HttpCacheStoreEvent $event): void
    {
        $event->request->attributes->set(CacheStatusResolver::STORED_ATTRIBUTE, true);
    }
}
