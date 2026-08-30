<?php declare(strict_types=1);

namespace Fastmon\Collector\Api;

/**
 * The stored token was rejected (HTTP 401).
 *
 * Tokens are revocable from fastmon's "Connected apps" screen at any time and there is
 * no refresh, so this is an expected state rather than a fault: the caller drops to the
 * reconnect path instead of retrying.
 */
/** Open on purpose: `FastmonCredentialExpiredException` narrows it. */
class FastmonUnauthorizedException extends FastmonApiException
{
}
