<?php declare(strict_types=1);

namespace Fastmon\Collector\Api;

/**
 * An OAuth-shaped refusal from `/auth/app/register`, `/token` or `/revoke`.
 *
 * Those three answer in the OAuth wire format (`{"error": "invalid_grant", ...}`, RFC 6749
 * §5.2) rather than in fastmon's error envelope, and the difference matters: `error` is a
 * fixed string a client may branch on, while `error_description` is prose that may be
 * reworded at any time. So the code is carried separately and the description is only
 * ever shown to a human.
 */
final class FastmonOAuthException extends FastmonApiException
{
    public function __construct(
        /** The OAuth error code, e.g. `invalid_grant`. Empty when the response carried none. */
        public readonly string $error,
        string $message,
    ) {
        parent::__construct($message);
    }
}
