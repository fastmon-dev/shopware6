<?php declare(strict_types=1);

namespace Fastmon\Collector\Subscriber;

use Fastmon\Collector\ServerTiming\PageTypeResolver;
use Fastmon\Collector\ServerTiming\RequestInsights;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records the few things about a storefront request that only the controller knows.
 *
 * None of it is recoverable afterwards, and on a full-page-cache hit none of it happens
 * at all - which is correct rather than a gap: fastmon classifies the page type from the
 * body classes of the cached HTML, and `PageTypeResolver` produces exactly the values
 * that ruleset produces, so the two cannot disagree. A render time is simply absent on a
 * hit, because nothing was rendered.
 *
 * There is deliberately no per-request reset here. A page and its own pagelets arrive as
 * separate passes through the inner kernel, so anything reset on `kernel.request` would
 * be torn down between the page and its footer - and the footer would then be what the
 * header reports. `RequestInsights` decides ownership instead, and is reset between
 * requests by the `kernel.reset` tag.
 */
class StorefrontInsightSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly PageTypeResolver $pageTypeResolver,
        private readonly RequestInsights $insights,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StorefrontRenderEvent::class => 'onRender',
            // Immediately after the controller returned, which is the closest thing
            // Shopware has to an "after render" event.
            KernelEvents::RESPONSE => ['onResponse', 10000],
        ];
    }

    public function onRender(StorefrontRenderEvent $event): void
    {
        $route = $event->getRequest()->attributes->get('_route');
        $pageType = $this->pageTypeResolver->resolve(\is_string($route) ? $route : null);

        // A pagelet route maps to no page type, and that is exactly what tells the page
        // apart from the fragments it contains.
        if ($pageType === null) {
            return;
        }

        $customer = $event->getSalesChannelContext()->getCustomer();

        // A guest checkout is not a login: the customer record exists for the order, but
        // nobody authenticated, and the two behave differently enough that counting them
        // together would blur exactly the comparison this is for.
        $this->insights->beginPage($pageType, $customer !== null && !$customer->getGuest());
    }

    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->insights->endRender();
        }
    }
}
