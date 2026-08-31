<?php declare(strict_types=1);

namespace Fastmon\Collector\Api;

/**
 * One token response from `/auth/app/token`, for either grant.
 *
 * `scope` is what was actually granted, which is regularly less than what was asked for:
 * the approver may tick fewer permissions than the registration declared, and their role
 * cuts the list again. It is read and stored rather than assumed, so the panel can say
 * which permission is missing before an API call fails on it.
 *
 * The account block only arrives on the authorization_code exchange - a refresh happens
 * with nobody present, so there is no identity to report and the fields are empty. The
 * caller keeps what it stored the first time.
 */
final readonly class OAuthTokens
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public int $expiresIn,
        public string $scope,
        public string $accountEmail,
        public string $accountName,
        public string $organizationId,
        public string $organizationName,
    ) {
    }
}
