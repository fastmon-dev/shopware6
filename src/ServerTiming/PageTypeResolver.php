<?php declare(strict_types=1);

namespace Fastmon\Collector\ServerTiming;

/**
 * The kind of page a storefront route renders, in fastmon's own vocabulary.
 *
 * fastmon already classifies page types client-side from the storefront's body classes
 * (the `shopware6` ruleset), and that keeps working on pages served from the full page
 * cache, where no controller runs. So this does not replace it - it is the more reliable
 * half of the pair. The ruleset reads CSS classes a theme is free to change and a plugin
 * is free to take over; a route name is what Shopware actually dispatched.
 *
 * The mapping deliberately produces the exact values the ruleset produces - home,
 * category, product, cart, checkout, search, account - so a page classified on a cache
 * miss here and on a cache hit there cannot disagree.
 */
class PageTypeResolver
{
    /**
     * Exact route names. Kept in step with the `shopware6` body-class map in fastmon's
     * `app/services/tracker/pagetype.py`, which maps the same routes in their dashed
     * spelling (`frontend-detail-page`).
     */
    private const ROUTES = [
        'frontend.home.page' => 'home',
        'frontend.navigation.page' => 'category',
        'frontend.cms.navigation.page' => 'category',
        'frontend.detail.page' => 'product',
        'frontend.checkout.cart.page' => 'cart',
        'frontend.checkout.confirm.page' => 'checkout',
        'frontend.checkout.finish.page' => 'checkout',
        'frontend.checkout.register.page' => 'checkout',
        'frontend.search.page' => 'search',
    ];

    /**
     * Prefixes for route families with an open tail. The account family alone has some
     * thirty routes (login, register, orders, profile, addresses, …); naming them all
     * would be a list to maintain rather than a rule.
     */
    private const PREFIXES = [
        'frontend.account.' => 'account',
        'frontend.checkout.' => 'cart',
    ];

    /**
     * Null when the route is not a storefront page worth typing - an AJAX endpoint, a
     * form POST, a widget. Emitting a type for those would put a page type on requests
     * that are not pageviews.
     */
    public function resolve(?string $route): ?string
    {
        if ($route === null || $route === '') {
            return null;
        }

        if (isset(self::ROUTES[$route])) {
            return self::ROUTES[$route];
        }

        // Only full pages. Shopware names every storefront page route `*.page`, so this
        // one suffix separates them from the endpoints that share the same prefixes -
        // `frontend.checkout.cart.json`, `frontend.account.login`, and so on.
        if (!str_ends_with($route, '.page')) {
            return null;
        }

        foreach (self::PREFIXES as $prefix => $type) {
            if (str_starts_with($route, $prefix)) {
                return $type;
            }
        }

        return null;
    }
}
