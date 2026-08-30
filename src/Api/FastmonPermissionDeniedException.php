<?php declare(strict_types=1);

namespace Fastmon\Collector\Api;

/**
 * The token authenticated but does not carry the permission this call needs
 * (HTTP 403, `permission_denied`).
 *
 * fastmon names the missing one in `details.permission`, and that detail is the whole
 * value of this class: it turns "403 Forbidden" into "this token is missing `app:write`",
 * which is the difference between a support ticket and a merchant fixing it themselves.
 *
 * On the guided flow this would be a configuration fault on our side - the consent grants
 * what the authorizing user holds, so a missing permission means the plugin asked for
 * something it was never scoped for. On the pasted-token path it is far more ordinary:
 * the merchant minted a key without `app:write`, or holds only the viewer role.
 */
final class FastmonPermissionDeniedException extends FastmonApiException
{
    public function __construct(
        string $message,
        /** The permission fastmon named, e.g. `app:write`. Empty when it named none. */
        public readonly string $permission = '',
    ) {
        parent::__construct($message);
    }
}
