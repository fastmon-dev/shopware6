<?php declare(strict_types=1);

namespace Fastmon\Collector\ScheduledTask;

use Fastmon\Collector\Connection\AccessTokenProvider;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the weekly renewal, and refuses to make a fuss about it.
 *
 * Every failure is caught. A shop that could not reach fastmon this week will try again
 * next week with the same token, which is still valid for weeks; letting the task fail
 * would put a red entry in the scheduled task list for a connection that is fine. The one
 * failure worth reading is a connection fastmon has ended, and that is logged as a
 * warning, once a week, next to the reason.
 */
#[AsMessageHandler(handles: RenewConnectionTask::class)]
#[WithMonologChannel('fastmon_collector')]
final class RenewConnectionTaskHandler extends ScheduledTaskHandler
{
    /**
     * @param EntityRepository<ScheduledTaskCollection> $scheduledTaskRepository
     */
    public function __construct(
        #[Autowire(service: 'scheduled_task.repository')]
        EntityRepository $scheduledTaskRepository,
        private readonly LoggerInterface $logger,
        private readonly AccessTokenProvider $tokens,
    ) {
        parent::__construct($scheduledTaskRepository, $logger);
    }

    public function run(): void
    {
        try {
            $this->tokens->renew();
        } catch (\Throwable $e) {
            $this->logger->warning('fastmon: the weekly connection renewal failed: ' . $e->getMessage());
        }
    }
}
