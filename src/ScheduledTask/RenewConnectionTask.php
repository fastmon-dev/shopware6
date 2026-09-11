<?php declare(strict_types=1);

namespace Fastmon\Collector\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Keeps the fastmon connection alive on a shop nobody is administering.
 *
 * A refresh token is valid for sixty days from the moment it was issued, and every use
 * issues the next one with a fresh sixty. Until now the only thing that ever used one was
 * somebody opening this plugin's configuration page, so a shop that runs quietly for two
 * months lost its connection to a clock rather than to anything that happened.
 *
 * Weekly is the interval that costs nothing and forgives everything: it is eight times
 * shorter than the window it protects, so the connection survives a worker that was down
 * for a month, a shop that was offline over Christmas, or a queue that was drained by
 * hand.
 */
#[AutoconfigureTag('shopware.scheduled.task')]
final class RenewConnectionTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'fastmon_collector.renew_connection';
    }

    public static function getDefaultInterval(): int
    {
        return self::WEEKLY;
    }
}
