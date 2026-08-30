<?php declare(strict_types=1);

namespace Fastmon\Collector\Api;

/**
 * The fastmon API answered, but not with what was asked for.
 *
 * The message carries fastmon's own error envelope (`code`, `message`) plus the
 * `request_id` when one was returned, because that is the string support needs to find
 * the request in the backend logs.
 */
/** Open on purpose: the base of the plugin's API error hierarchy. */
class FastmonApiException extends \RuntimeException
{
}
