<?php declare(strict_types=1);

namespace Fastmon\Collector\Provisioning;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

/**
 * "The storefront domains changed, tell fastmon."
 *
 * Carries nothing: the handler reads the sales channels fresh, so three domain writes
 * in a row are three identical messages and the last one sees everything. Asynchronous
 * because the alternative is an HTTP call to fastmon inside the admin's save of a
 * sales channel, which would make that save wait on a third party.
 */
final class SyncSitesMessage implements AsyncMessageInterface
{
}
