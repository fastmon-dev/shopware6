<?php declare(strict_types=1);

namespace Fastmon\Collector\Subscriber;

use Fastmon\Collector\Provisioning\SyncSitesMessage;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainDefinition;
use Shopware\Core\System\SalesChannel\SalesChannelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues a site sync when the set of storefront domains may have changed.
 *
 * A domain written is the obvious case. A sales channel written counts only when the
 * write switched it on: a channel that was inactive brought no domains, and now does.
 * Everything else a channel save touches (name, currency, payment methods) leaves the
 * domain set alone, and firing on each of those would be a fastmon call per admin save.
 *
 * Not on the storefront path, and talks to nobody: it hands the bus a message and the
 * handler does the rest, later.
 */
final class SalesChannelDomainSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SalesChannelDomainDefinition::ENTITY_NAME . '.written' => 'onDomainWritten',
            SalesChannelEvents::SALES_CHANNEL_WRITTEN => 'onSalesChannelWritten',
        ];
    }

    public function onDomainWritten(EntityWrittenEvent $event): void
    {
        unset($event);

        $this->bus->dispatch(new SyncSitesMessage());
    }

    public function onSalesChannelWritten(EntityWrittenEvent $event): void
    {
        foreach ($event->getPayloads() as $payload) {
            if (($payload['active'] ?? false) === true) {
                $this->bus->dispatch(new SyncSitesMessage());

                return;
            }
        }
    }
}
