<?php declare(strict_types=1);

namespace Fastmon\Collector;

/**
 * A refusal the merchant is meant to read.
 *
 * Everything here is a state of the shop's own setup - nothing linked yet, nothing typed
 * in - and the administration shows the message next to the button that caused it. Being
 * its own type is what lets the controller catch exactly these and let anything else
 * propagate: a `\RuntimeException` from somewhere unexpected is a fault, and a fault
 * belongs in the log as an error, not in a panel as text.
 */
final class FastmonCollectorException extends \RuntimeException
{
    public static function noApplicationLinked(): self
    {
        return new self('No fastmon application is linked to this shop.');
    }

    public static function customDomainMissing(): self
    {
        return new self('Enter the domain the tracker and beacon should be served from.');
    }

    public static function applicationWithoutTrackerId(): self
    {
        return new self('That fastmon application has no tracker id.');
    }

    public static function emptyToken(): self
    {
        return new self('No token was given.');
    }
}
