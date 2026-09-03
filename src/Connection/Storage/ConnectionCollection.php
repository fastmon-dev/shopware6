<?php declare(strict_types=1);

namespace Fastmon\Collector\Connection\Storage;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<ConnectionEntity>
 */
final class ConnectionCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return ConnectionEntity::class;
    }
}
