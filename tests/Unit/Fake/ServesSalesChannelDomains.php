<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit\Fake;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainDefinition;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;

/**
 * A `sales_channel_domain` repository serving exactly the URLs a test names.
 *
 * The filters themselves are not exercised here and cannot be: whether
 * `salesChannel.active` and `salesChannel.typeId` select what the docblock promises is a
 * question about the DAL and the schema, which only a booted Shopware can answer. That is
 * `EndpointCheckerStorefrontOriginsTest`, on real rows. What this fake is for is
 * everything after the read: the origin normalisation and the probing.
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait ServesSalesChannelDomains
{
    /**
     * @param list<string> $urls
     *
     * @return EntityRepository<SalesChannelDomainCollection>
     */
    private function domainRepository(array $urls): EntityRepository
    {
        $entities = [];

        foreach ($urls as $index => $url) {
            $domain = new SalesChannelDomainEntity();
            $domain->setUniqueIdentifier('domain-' . $index);
            $domain->setUrl($url);
            $entities[] = $domain;
        }

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn(new EntitySearchResult(
            SalesChannelDomainDefinition::ENTITY_NAME,
            \count($entities),
            new SalesChannelDomainCollection($entities),
            null,
            new Criteria(),
            Context::createDefaultContext()
        ));

        return $repository;
    }
}
