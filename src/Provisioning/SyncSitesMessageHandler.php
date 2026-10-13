<?php declare(strict_types=1);

namespace Fastmon\Collector\Provisioning;

use Fastmon\Collector\Connection\ConnectionStore;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the site sync from the queue, and refuses to make a fuss about it.
 *
 * Every failure is caught: a sync that could not reach fastmon is retried by the next
 * sales channel save and by the button in the panel, and a failed message would sit in
 * the failure transport for a connection that is fine. The one thing worth reading is
 * logged as a warning, next to the reason.
 */
#[AsMessageHandler]
#[WithMonologChannel('fastmon_collector')]
final class SyncSitesMessageHandler
{
    public function __construct(
        private readonly SiteSynchroniser $sites,
        private readonly ConnectionStore $store,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncSitesMessage $message): void
    {
        unset($message);

        // A shop without a linked application has nowhere to put the domains. Checked
        // here rather than before dispatch so the subscriber stays a one-liner that
        // reads nothing.
        if ($this->store->load()->applicationId === '') {
            return;
        }

        try {
            $this->sites->sync();
        } catch (\Throwable $e) {
            $this->logger->warning('fastmon: the site sync after a sales channel change failed: ' . $e->getMessage());
        }
    }
}
