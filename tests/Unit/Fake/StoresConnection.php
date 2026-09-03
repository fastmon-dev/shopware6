<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit\Fake;

use Fastmon\Collector\Connection\ConnectionStore;
use Fastmon\Collector\Connection\Storage\ConnectionCollection;
use Fastmon\Collector\Connection\Storage\ConnectionDefinition;
use Fastmon\Collector\Connection\Storage\ConnectionEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Event\NestedEventCollection;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The connection as two plain arrays: the row it lives in, and the two `system_config`
 * values the storefront renders.
 *
 * Asserting against `$this->row` says exactly what ends up in
 * `fastmon_collector_connection`, and against `$this->config` what ends up in
 * `system_config`. That split is the point of the storage rather than an artefact of the
 * test: a token is a row, and the source_hash is configuration the page cache depends on.
 *
 * Reads always answer from the current arrays, which is what the real storage does too:
 * nothing memoises the row, and the refresh path depends on that.
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait StoresConnection
{
    /**
     * The row, keyed by the entity's property names.
     *
     * @var array<string, mixed>
     */
    private array $row = [];

    /** @var array<string, mixed> */
    private array $config = [];

    /**
     * Every payload that was written, in order, for the tests that care what arrived
     * together rather than only what the row ended up holding.
     *
     * @var list<array<string, mixed>>
     */
    private array $writes = [];

    private function connectionStore(?SystemConfigService $systemConfig = null): ConnectionStore
    {
        return new ConnectionStore($this->connectionRepository(), $systemConfig ?? $this->systemConfig());
    }

    /**
     * @return EntityRepository<ConnectionCollection>
     */
    private function connectionRepository(): EntityRepository
    {
        $mock = $this->createMock(EntityRepository::class);

        $mock->method('search')->willReturnCallback(
            function (Criteria $criteria, Context $context): EntitySearchResult {
                $collection = new ConnectionCollection($this->row === [] ? [] : [$this->entity()]);

                return new EntitySearchResult(
                    ConnectionDefinition::ENTITY_NAME,
                    $collection->count(),
                    $collection,
                    null,
                    $criteria,
                    $context
                );
            }
        );

        $mock->method('upsert')->willReturnCallback(
            function (array $data, Context $context): EntityWrittenContainerEvent {
                foreach ($data as $entry) {
                    $payload = $this->payload($entry);
                    $this->writes[] = $payload;
                    // Merged, not replaced: one upsert names the fields it writes and
                    // leaves the rest of the row alone, which is what the real one does.
                    $this->row = array_merge($this->row, $payload);
                }

                return $this->written($context);
            }
        );

        $mock->method('delete')->willReturnCallback(
            function (array $ids, Context $context): EntityWrittenContainerEvent {
                unset($ids);
                $this->row = [];

                return $this->written($context);
            }
        );

        return $mock;
    }

    /**
     * A `SystemConfigService` that is a plain array. No `silent` argument anywhere: the
     * only keys this plugin writes through it are the two the storefront renders, and
     * those are meant to invalidate the pages that carry them.
     */
    private function systemConfig(): SystemConfigService
    {
        $systemConfig = $this->createMock(SystemConfigService::class);

        $systemConfig->method('get')->willReturnCallback(
            fn (string $key): mixed => $this->config[$key] ?? null
        );
        $systemConfig->method('set')->willReturnCallback(
            /** @param string|null $salesChannelId everything this plugin writes is global */
            function (string $key, mixed $value, ?string $salesChannelId = null): void {
                unset($salesChannelId);
                $this->config[$key] = $value;
            }
        );
        $systemConfig->method('delete')->willReturnCallback(
            function (string $key, ?string $salesChannelId = null): void {
                unset($salesChannelId, $this->config[$key]);
            }
        );

        return $systemConfig;
    }

    /**
     * One write payload, without the primary key every upsert carries.
     *
     * @return array<string, mixed>
     */
    private function payload(mixed $entry): array
    {
        $payload = [];

        foreach (\is_array($entry) ? $entry : [] as $field => $value) {
            if (\is_string($field) && $field !== 'id') {
                $payload[$field] = $value;
            }
        }

        return $payload;
    }

    private function entity(): ConnectionEntity
    {
        $entity = new ConnectionEntity();
        $entity->setId(ConnectionDefinition::ROW_ID);
        $entity->setUniqueIdentifier(ConnectionDefinition::ROW_ID);
        $entity->assign($this->row);

        return $entity;
    }

    private function written(Context $context): EntityWrittenContainerEvent
    {
        return new EntityWrittenContainerEvent($context, new NestedEventCollection(), []);
    }
}
