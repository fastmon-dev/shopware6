<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\Api\FastmonClient;
use Fastmon\Collector\Collection\CollectionModeService;
use Fastmon\Collector\ServerTiming\LayerMetricsProviderRegistry;
use Fastmon\Collector\ServerTiming\ServerIdentity;
use Fastmon\Collector\ServerTiming\TidewaysLayerMetricsProvider;
use Fastmon\Collector\Service\ConfigResolver;
use Fastmon\Collector\Subscriber\HttpCacheStatusSubscriber;
use Fastmon\Collector\Subscriber\ServerTimingSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\AutowirePass;
use Symfony\Component\DependencyInjection\Compiler\RegisterAutoconfigureAttributesPass;
use Symfony\Component\DependencyInjection\Compiler\ResolveClassPass;
use Symfony\Component\DependencyInjection\Compiler\ResolveInstanceofConditionalsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Lock\LockFactory;

/**
 * The plugin must not be able to make a storefront request slower, and must not be able
 * to make one fail.
 *
 * The sharpest edge of that is the network: a synchronous call to fastmon on the request
 * path would put a third party's availability in front of every page the shop serves.
 * The design keeps every such call inside the admin module, but "the design does" is not
 * a guarantee - one convenient constructor argument would undo it silently, and the
 * symptom would be a shop that intermittently hangs rather than a failing test.
 *
 * So this asserts it structurally, against the real service definitions: nothing the
 * storefront path can reach may depend on the API client or on an HTTP client.
 *
 * Imports half the DI compiler on purpose: the walk has to see what the kernel sees.
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class StorefrontPathIsIsolatedTest extends TestCase
{
    /** Everything that runs while a storefront request is being answered. */
    private const REQUEST_PATH_SERVICES = [
        ServerTimingSubscriber::class,
        HttpCacheStatusSubscriber::class,
    ];

    /** Things whose presence on the request path would mean a network call. */
    private const FORBIDDEN = [
        FastmonClient::class,
        'Fastmon\Collector\Api\FastmonOAuthClient',
        'fastmon_collector.http_client',
        'Fastmon\Collector\Connection\ConnectionService',
        'Fastmon\Collector\Connection\ConnectionStatus',
        // Hands out an access token, and refreshes it over the network when it is due.
        'Fastmon\Collector\Connection\AccessTokenProvider',
        'Fastmon\Collector\Provisioning\ApplicationProvisioner',
    ];

    public function testNothingOnTheRequestPathCanTalkToFastmon(): void
    {
        $container = $this->container();

        foreach (self::REQUEST_PATH_SERVICES as $entryPoint) {
            $reachable = $this->closure($container, $entryPoint);

            foreach (self::FORBIDDEN as $forbidden) {
                self::assertNotContains(
                    $forbidden,
                    $reachable,
                    sprintf(
                        '%s can reach %s. A storefront response must never depend on fastmon being up.',
                        $entryPoint,
                        $forbidden
                    )
                );
            }
        }
    }

    public function testTheWalkSeesRealDependencies(): void
    {
        $container = $this->container();

        // Positive control: what the request path is known to need.
        $reachable = $this->closure($container, ServerTimingSubscriber::class);

        foreach ([ConfigResolver::class, LayerMetricsProviderRegistry::class, TidewaysLayerMetricsProvider::class, ServerIdentity::class] as $needed) {
            self::assertContains($needed, $reachable);
        }

        // Negative control: a service that does talk to fastmon is seen doing so.
        self::assertContains(FastmonClient::class, $this->closure($container, CollectionModeService::class));
    }

    public function testTheSubscriberSwallowsEveryFailure(): void
    {
        // A monitoring header is never worth a broken response, so the whole body is
        // wrapped. Asserted on the source because the alternative - provoking each of
        // the ways config, profiler or writer could throw - tests the mocks instead.
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(ServerTimingSubscriber::class))->getFileName()
        );

        self::assertStringContainsString('catch (\Throwable $e)', $source);
    }

    /**
     * @return list<string> every service id reachable from $id, $id included
     */
    private function closure(ContainerBuilder $container, string $id): array
    {
        $seen = [];
        $queue = [$id];

        while ($queue !== []) {
            $current = array_shift($queue);

            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;

            if ($container->hasAlias($current)) {
                $queue[] = (string) $container->getAlias($current);

                continue;
            }

            if (!$container->hasDefinition($current)) {
                continue;
            }

            foreach ($this->references($container, $container->getDefinition($current)) as $reference) {
                $queue[] = $reference;
            }
        }

        return array_keys($seen);
    }

    /**
     * @return list<string>
     */
    private function references(ContainerBuilder $container, Definition $definition): array
    {
        $found = [];

        $walk = static function (mixed $value) use (&$walk, &$found, $container): void {
            if ($value instanceof Reference) {
                $found[] = (string) $value;

                return;
            }

            // A tagged iterator names its members by tag rather than by id, and it is
            // the plugin's own extension point - the measurement providers arrive
            // through one. Resolving it is what keeps a future provider from quietly
            // dragging the API client onto the request path.
            if ($value instanceof TaggedIteratorArgument) {
                foreach (array_keys($container->findTaggedServiceIds($value->getTag())) as $tagged) {
                    $found[] = (string) $tagged;
                }

                return;
            }

            if (is_iterable($value)) {
                foreach ($value as $item) {
                    $walk($item);
                }
            }
        };

        $walk($definition->getArguments());

        foreach ($definition->getMethodCalls() as $call) {
            if (\is_array($call)) {
                $walk($call[1] ?? []);
            }
        }

        return $found;
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();

        // What the shop provides and the plugin only consumes. Synthetic: the walk needs
        // the ids to resolve, not the objects.
        $container->register(SystemConfigService::class, SystemConfigService::class)->setSynthetic(true);
        $container->register('sales_channel_domain.repository', EntityRepository::class)->setSynthetic(true);
        $container->register('scheduled_task.repository', EntityRepository::class)->setSynthetic(true);
        $container->register(CacheInvalidator::class, CacheInvalidator::class)->setSynthetic(true);
        $container->register('lock.factory', LockFactory::class)->setSynthetic(true);
        $container->register('logger', NullLogger::class);
        $container->setAlias(LoggerInterface::class, 'logger');

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../src/Resources/config'));
        $loader->load('services.yaml');

        // The part of the compiler that turns constructor types and #[Autowire…]
        // attributes into references, in the kernel's order. AutowirePass throws on
        // anything it cannot resolve, so a missing stub fails here rather than leaving
        // a definition without arguments - which the walk would read as "depends on
        // nothing".
        foreach ([
            new ResolveClassPass(),
            new RegisterAutoconfigureAttributesPass(),
            new ResolveInstanceofConditionalsPass(),
            new AutowirePass(),
        ] as $pass) {
            $pass->process($container);
        }

        return $container;
    }
}
