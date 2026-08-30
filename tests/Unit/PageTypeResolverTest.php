<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\ServerTiming\PageTypeResolver;
use PHPUnit\Framework\TestCase;

class PageTypeResolverTest extends TestCase
{
    private PageTypeResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new PageTypeResolver();
    }

    /**
     * The values have to be the ones fastmon's own `shopware6` body-class ruleset
     * produces. A page typed here on a cache miss and there on a cache hit must not
     * disagree, or the dimension becomes noise.
     */
    public function testProducesTheSameVocabularyAsTheBodyClassRuleset(): void
    {
        $expected = [
            'frontend.home.page' => 'home',
            'frontend.navigation.page' => 'category',
            'frontend.detail.page' => 'product',
            'frontend.checkout.cart.page' => 'cart',
            'frontend.checkout.confirm.page' => 'checkout',
            'frontend.checkout.finish.page' => 'checkout',
            'frontend.checkout.register.page' => 'checkout',
            'frontend.search.page' => 'search',
        ];

        foreach ($expected as $route => $type) {
            self::assertSame($type, $this->resolver->resolve($route), $route);
        }
    }

    public function testTheAccountFamilyIsMatchedByPrefix(): void
    {
        // Some thirty routes; naming them all would be a list to maintain, not a rule.
        foreach ([
            'frontend.account.home.page',
            'frontend.account.login.page',
            'frontend.account.order.page',
            'frontend.account.profile.page',
            'frontend.account.address.page',
        ] as $route) {
            self::assertSame('account', $this->resolver->resolve($route), $route);
        }
    }

    public function testOnlyFullPagesAreTyped(): void
    {
        // Shopware names every storefront page route `*.page`, which separates them from
        // the endpoints sharing the same prefixes. A page type on an AJAX call would put
        // one on a request that is not a pageview.
        foreach ([
            'frontend.checkout.cart.json',
            'frontend.checkout.line-item.add',
            'frontend.account.login',
            'frontend.detail.switch',
            'frontend.search.suggest',
            'api.action.something',
        ] as $route) {
            self::assertNull($this->resolver->resolve($route), $route);
        }
    }

    public function testNoRouteMeansNoType(): void
    {
        self::assertNull($this->resolver->resolve(null));
        self::assertNull($this->resolver->resolve(''));
    }
}
