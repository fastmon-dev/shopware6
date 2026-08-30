<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\ServerTiming\CacheStatusResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CacheStatusResolverTest extends TestCase
{
    private CacheStatusResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new CacheStatusResolver();
    }

    public function testReportsAHitWhenTheCacheAnswered(): void
    {
        $request = $this->request();
        $request->attributes->set(CacheStatusResolver::HIT_ATTRIBUTE, true);

        self::assertSame('hit', $this->resolver->resolve($request, $this->html()));
    }

    public function testReportsAMissWhenTheCacheStoredTheResponse(): void
    {
        $request = $this->request();
        $request->attributes->set(CacheStatusResolver::STORED_ATTRIBUTE, true);

        self::assertSame('miss', $this->resolver->resolve($request, $this->html()));
    }

    public function testAStoredResponseIsAMissEvenThoughShopwareMarksItPrivate(): void
    {
        // The case that broke the header heuristic this replaced: Shopware serves a
        // perfectly cacheable storefront page with `Cache-Control: no-cache, private`,
        // because that header governs the browser and not its own reverse-proxy cache.
        // Reading intent out of it reported `bypass` for every page the cache was
        // actually working on, so `miss` never appeared at all.
        $request = $this->request();
        $request->attributes->set(CacheStatusResolver::STORED_ATTRIBUTE, true);

        $response = $this->html();
        $response->headers->set('Cache-Control', 'no-cache, private');

        self::assertSame('miss', $this->resolver->resolve($request, $response));
    }

    public function testReportsBypassWhenTheCacheNeitherAnsweredNorStored(): void
    {
        // A logged-in customer, a filled cart, a POST. Counting these as misses would
        // make the cache rate look terrible on a shop whose cache is working perfectly.
        self::assertSame('bypass', $this->resolver->resolve($this->request(), $this->html()));
    }

    public function testAHitWinsOverAStore(): void
    {
        // Shopware can revalidate and re-store while serving from the cache; what the
        // visitor got is still a hit.
        $request = $this->request();
        $request->attributes->set(CacheStatusResolver::HIT_ATTRIBUTE, true);
        $request->attributes->set(CacheStatusResolver::STORED_ATTRIBUTE, true);

        self::assertSame('hit', $this->resolver->resolve($request, $this->html()));
    }

    public function testSaysNothingAboutSubResources(): void
    {
        // fastmon only reads the navigation entry, so a verdict on a stylesheet is read
        // by nobody.
        $response = new Response('', 200, ['Content-Type' => 'text/css']);

        self::assertNull($this->resolver->resolve($this->request(), $response));
    }

    private function request(): Request
    {
        return Request::create('https://shop.example/', 'GET');
    }

    private function html(): Response
    {
        return new Response('', 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
