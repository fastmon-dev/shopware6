<?php declare(strict_types=1);

namespace Fastmon\Collector\Api;

/**
 * A started device authorization (RFC 8628 §3.2).
 *
 * `deviceCode` is the secret the shop polls with and never shows anyone. `userCode` is
 * the short string the merchant types into fastmon. `verificationUriComplete` embeds the
 * user code in the URL so the common case is one click and no typing; it is optional in
 * the RFC, so the plain `verificationUri` is always populated as the fallback.
 */
final class DeviceAuthorization
{
    public function __construct(
        public readonly string $deviceCode,
        public readonly string $userCode,
        public readonly string $verificationUri,
        public readonly string $verificationUriComplete,
        public readonly int $expiresIn,
        public readonly int $interval,
    ) {
    }
}
