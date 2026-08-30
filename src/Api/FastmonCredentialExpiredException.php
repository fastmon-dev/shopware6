<?php declare(strict_types=1);

namespace Fastmon\Collector\Api;

/**
 * The token had an expiry date and reached it (HTTP 401, `credential_expired`).
 *
 * A subclass of the unauthorized case because the reaction is the same - connect again -
 * but the reason is worth keeping apart. "Your connection expired" is a fact about time;
 * a bare 401 makes a merchant go looking for what they broke, or suspect the plugin.
 */
final class FastmonCredentialExpiredException extends FastmonUnauthorizedException
{
}
