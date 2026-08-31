<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\ServerTiming\CacheStatusResolver;
use Fastmon\Collector\ServerTiming\RequestInsights;
use Fastmon\Collector\ServerTiming\ServerIdentity;
use Fastmon\Collector\ServerTiming\ServerTimingHeaderBuilder;
use Fastmon\Collector\ServerTiming\ServerTimingResponseWriter;
use Fastmon\Collector\Service\ConfigResolver;
use Fastmon\Collector\Subscriber\ServerTimingSubscriber;
use Fastmon\Collector\Tests\Unit\Fake\FakeLayerMetricsProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Event\BeforeSendResponseEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ServerTimingSubscriberTest extends TestCase
{
    private RequestInsights $insights;

    protected function setUp(): void
    {
        $this->insights = new RequestInsights();
    }

    public function testReportsWhatTheControllerSaw(): void
    {
        // Render time, page type and login state come from the controller, and they have
        // to survive the trip to where the header is written - which a request attribute
        // does not, because the HTTP cache forwards a duplicated Request.
        $this->insights->beginPage('product', true);
        $this->insights->endRender();

        $subscriber = $this->subscriber([], available: false, config: ['serverTimingLoggedIn' => true]);
        $request = $this->request();
        $request->attributes->set(CacheStatusResolver::STORED_ATTRIBUTE, true);
        $response = $this->html();

        $subscriber->onBeforeSendResponse(new BeforeSendResponseEvent($request, $response));

        $header = (string) $response->headers->get('Server-Timing');

        self::assertMatchesRegularExpression('/fm-render;dur=[0-9.]+/', $header);
        self::assertStringContainsString('fm-pagetype;desc=product', $header);
        self::assertStringContainsString('fm-loggedin;desc=yes', $header);
    }

    public function testTheLoginFlagStaysOffUnlessAskedFor(): void
    {
        // A visitor attribute in a header fastmon collects in every privacy mode. Opting
        // in is a decision about that classification, so the default cannot be "on".
        $this->insights->beginPage('product', true);

        $subscriber = $this->subscriber([], available: false);
        $request = $this->request();
        $request->attributes->set(CacheStatusResolver::STORED_ATTRIBUTE, true);
        $response = $this->html();

        $subscriber->onBeforeSendResponse(new BeforeSendResponseEvent($request, $response));

        self::assertStringNotContainsString('fm-loggedin', (string) $response->headers->get('Server-Timing'));
    }

    public function testNoRenderTimeIsReportedWhenNothingWasRendered(): void
    {
        // The cache-hit case. A zero would be a real-looking number dragging every
        // average towards it.
        $subscriber = $this->subscriber([], available: false);
        $request = $this->request();
        $request->attributes->set(CacheStatusResolver::HIT_ATTRIBUTE, true);
        $response = $this->html();

        $subscriber->onBeforeSendResponse(new BeforeSendResponseEvent($request, $response));

        self::assertStringNotContainsString('fm-render', (string) $response->headers->get('Server-Timing'));
    }

    public function testWritesTheHeaderOnACacheMiss(): void
    {
        $subscriber = $this->subscriber(['rdbms' => 42.5]);
        $request = $this->request();
        $request->attributes->set(CacheStatusResolver::STORED_ATTRIBUTE, true);
        $response = $this->html();

        $subscriber->onBeforeSendResponse(new BeforeSendResponseEvent($request, $response));

        $header = $response->headers->get('Server-Timing');
        self::assertStringContainsString('fm-fpc;desc=miss', (string) $header);
        self::assertStringContainsString('rdbms;dur=42.5', (string) $header);
    }

    public function testACacheHitDoesNotReportTheTimingsOfTheRequestThatFilledTheCache(): void
    {
        // The reason this subscriber listens to BeforeSendResponseEvent at all. On a
        // full-page-cache hit the inner kernel never runs, so a kernel.response listener
        // would not fire and the browser would receive the stored header - a complete,
        // plausible and entirely wrong set of backend timings belonging to whichever
        // request happened to populate the cache.
        $subscriber = $this->subscriber([]);

        $request = $this->request();
        $request->attributes->set(CacheStatusResolver::HIT_ATTRIBUTE, true);

        $response = $this->html();
        $response->headers->set('Server-Timing', 'fm-fpc;desc=miss, fm-backend;dur=250.0, rdbms;dur=200.0');

        $subscriber->onBeforeSendResponse(new BeforeSendResponseEvent($request, $response));

        $values = $response->headers->all('Server-Timing');
        $joined = implode(' | ', $values);

        self::assertStringContainsString('fm-fpc;desc=hit', $joined);
        self::assertStringNotContainsString('fm-backend;dur=250.0', $joined);
        // A layer entry that is not ours is left alone: only `fm-` names are ours to
        // replace.
        self::assertStringContainsString('rdbms;dur=200.0', $joined);
    }

    public function testReportsTheCacheVerdictEvenWithoutAProfiler(): void
    {
        // Needs no extension and is the single most useful entry in the header, so a
        // plain host still gets something worth reading.
        $subscriber = $this->subscriber([], available: false);
        $request = $this->request();
        $request->attributes->set(CacheStatusResolver::STORED_ATTRIBUTE, true);
        $response = $this->html();

        $subscriber->onBeforeSendResponse(new BeforeSendResponseEvent($request, $response));

        // The node name comes from the machine, so it is normalised away with the
        // duration - what matters here is that the entries are emitted at all.
        self::assertSame(
            'fm-fpc;desc=miss, fm-host;desc=node, fm-backend;dur=0.0',
            $this->normalise($response)
        );
    }

    public function testWritesNothingWhenTurnedOff(): void
    {
        $subscriber = $this->subscriber(['rdbms' => 42.5], config: ['serverTiming' => false]);
        $response = $this->html();

        $subscriber->onBeforeSendResponse(new BeforeSendResponseEvent($this->request(), $response));

        self::assertFalse($response->headers->has('Server-Timing'));
    }

    public function testSkipsEsiSubRequests(): void
    {
        // Their headers are discarded when the fragment is embedded.
        $subscriber = $this->subscriber(['rdbms' => 42.5]);

        $request = $this->request();
        $request->attributes->set('_sw_esi', true);

        $response = $this->html();
        $subscriber->onBeforeSendResponse(new BeforeSendResponseEvent($request, $response));

        self::assertFalse($response->headers->has('Server-Timing'));
    }

    public function testSaysNothingWhenThereIsNothingToSay(): void
    {
        // No profiler, nothing measured, everything switched off.
        $subscriber = $this->subscriber([], available: false, config: [
            'serverTimingCacheStatus' => false,
            'serverTimingTotal' => false,
            'serverTimingServer' => false,
        ]);
        $response = $this->html();

        $subscriber->onBeforeSendResponse(new BeforeSendResponseEvent($this->request(), $response));

        self::assertFalse($response->headers->has('Server-Timing'));
    }

    public function testCategoricalEntriesStayOffSubResources(): void
    {
        // A stylesheet carries no navigation entry, so a page type or a node name on it
        // is read by nobody - it would only add bytes to every response on the page.
        $subscriber = $this->subscriber(['rdbms' => 42.5]);
        $response = new Response('', 200, ['Content-Type' => 'text/css']);

        $subscriber->onBeforeSendResponse(new BeforeSendResponseEvent($this->request(), $response));

        $header = (string) $response->headers->get('Server-Timing');

        self::assertStringNotContainsString('fm-fpc', $header);
        self::assertStringNotContainsString('fm-host', $header);
        // The durations still go out: useful in devtools on any request.
        self::assertStringContainsString('rdbms;dur=42.5', $header);
    }

    /**
     * The total is wall time, so it differs on every run. Rounding it away keeps the
     * assertions about what is in the header rather than how fast the test machine is.
     */
    private function normalise(Response $response): string
    {
        return (string) preg_replace(
            ['/fm-backend;dur=[0-9.]+/', '/fm-host;desc=[^,]+/'],
            ['fm-backend;dur=0.0', 'fm-host;desc=node'],
            (string) $response->headers->get('Server-Timing')
        );
    }

    private function request(): Request
    {
        return Request::create('https://shop.example/', 'GET');
    }

    private function html(): Response
    {
        return new Response('', 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * @param array<string, float> $metrics
     * @param array<string, mixed> $config
     */
    private function subscriber(array $metrics, bool $available = true, array $config = []): ServerTimingSubscriber
    {
        $config += [
            'serverTiming' => true,
            'serverTimingTotal' => true,
            'serverTimingCacheStatus' => true,
            'serverTimingServer' => true,
            'blockedServerTimingLayers' => 'unknown',
        ];

        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            static function (string $key) use ($config): mixed {
                $short = str_replace(ConfigResolver::DOMAIN, '', $key);

                return $config[$short] ?? null;
            }
        );

        return new ServerTimingSubscriber(
            new ConfigResolver($systemConfig),
            new FakeLayerMetricsProvider($available, $metrics),
            new ServerTimingHeaderBuilder(),
            new ServerTimingResponseWriter(),
            new CacheStatusResolver(),
            $this->insights,
            new ServerIdentity(),
            new NullLogger(),
        );
    }
}
