<?php declare(strict_types=1);

namespace Fastmon\Collector\Api;

/**
 * This fastmon instance has no device-authorization endpoint (HTTP 404 / 405 on
 * `/auth/app/device/code`).
 *
 * Separated from a generic API error because it is not a failure the merchant can do
 * anything about, and because the answer to it is specific: connect with a token
 * created in the fastmon dashboard instead. The admin module says exactly that.
 */
final class DeviceFlowUnsupportedException extends FastmonApiException
{
}
