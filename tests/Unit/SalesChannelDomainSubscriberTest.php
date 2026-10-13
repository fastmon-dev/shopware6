<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\Provisioning\SyncSitesMessage;
use Fastmon\Collector\Subscriber\SalesChannelDomainSubscriber;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class SalesChannelDomainSubscriberTest extends TestCase
{
    /** @var list<object> */
    private array $dispatched = [];

    public function testADomainWriteQueuesASync(): void
    {
        $this->subscriber()->onDomainWritten($this->written('sales_channel_domain', [
            ['url' => 'https://shop.example'],
        ]));

        self::assertCount(1, $this->dispatched);
        self::assertInstanceOf(SyncSitesMessage::class, $this->dispatched[0]);
    }

    public function testASalesChannelSwitchedOnQueuesASync(): void
    {
        // Its domains were there all along; what changed is that they now serve pages.
        $this->subscriber()->onSalesChannelWritten($this->written('sales_channel', [
            ['name' => 'Outlet'],
            ['active' => true],
        ]));

        self::assertCount(1, $this->dispatched);
    }

    public function testAnyOtherSalesChannelSaveIsNotAFastmonCall(): void
    {
        // Renaming a channel, or switching one off, leaves the set of domains that
        // should be registered as it is.
        $this->subscriber()->onSalesChannelWritten($this->written('sales_channel', [
            ['name' => 'Outlet'],
            ['active' => false],
        ]));

        self::assertSame([], $this->dispatched);
    }

    public function testItListensToExactlyTheTwoWrites(): void
    {
        self::assertSame(
            ['sales_channel_domain.written', 'sales_channel.written'],
            array_keys(SalesChannelDomainSubscriber::getSubscribedEvents())
        );
    }

    /**
     * @param list<array<string, mixed>> $payloads
     */
    private function written(string $entity, array $payloads): EntityWrittenEvent
    {
        $results = [];

        foreach ($payloads as $i => $payload) {
            $results[] = new EntityWriteResult('id-' . $i, $payload, $entity, EntityWriteResult::OPERATION_UPDATE);
        }

        return new EntityWrittenEvent($entity, $results, Context::createDefaultContext());
    }

    private function subscriber(): SalesChannelDomainSubscriber
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        return new SalesChannelDomainSubscriber($bus);
    }
}
