<?php declare(strict_types=1);

namespace Fastmon\Collector\Api;

/**
 * The outcome of one poll against the token endpoint.
 *
 * @internal produced by FastmonClient
 */
final readonly class DevicePollResult
{
    private function __construct(
        public DevicePollStatus $status,
        public string $token,
        public string $accountEmail,
        public string $accountName,
        /**
         * The organization the merchant approved for, when fastmon named one.
         *
         * Empty against an instance that does not return it yet, and the plugin then
         * falls back to asking - so this is additive on both sides. When it is present
         * the shop never asks a question fastmon has already had answered, which also
         * removes the case where the merchant approves for one organization on the
         * consent screen and then picks a different one in the shop.
         */
        public string $organizationId,
        public string $organizationName,
    ) {
    }

    public static function pending(): self
    {
        return new self(DevicePollStatus::PENDING, '', '', '', '', '');
    }

    public static function slowDown(): self
    {
        return new self(DevicePollStatus::SLOW_DOWN, '', '', '', '', '');
    }

    public static function complete(
        string $token,
        string $email,
        string $name,
        string $organizationId = '',
        string $organizationName = '',
    ): self {
        return new self(DevicePollStatus::COMPLETE, $token, $email, $name, $organizationId, $organizationName);
    }
}
