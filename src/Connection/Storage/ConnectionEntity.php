<?php declare(strict_types=1);

namespace Fastmon\Collector\Connection\Storage;

use DateTimeInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

/**
 * One row of `fastmon_collector_connection`, as the DAL hydrates it.
 *
 * Plain public properties: the DAL assigns them and `ConnectionStore` reads them, and it
 * is the only reader. Nothing outside the store sees this class; callers get the
 * `Connection` and `Credentials` value objects, which know what an empty column means.
 *
 * One property per column, so it is as wide as the connection is: the registration, the
 * two tokens with their expiry, the pasted key, who approved it, what it points at, and
 * the authorization in flight. Splitting it would mean splitting the row.
 * @SuppressWarnings("PHPMD.TooManyFields")
 */
final class ConnectionEntity extends Entity
{
    use EntityIdTrait;

    public ?string $clientId = null;

    public ?string $redirectUri = null;

    public ?string $accessToken = null;

    public ?DateTimeInterface $accessTokenExpiresAt = null;

    public ?string $refreshToken = null;

    public ?string $scopes = null;

    public ?string $manualToken = null;

    public ?string $accountEmail = null;

    public ?string $accountName = null;

    public ?string $organizationId = null;

    public ?string $organizationName = null;

    public ?string $applicationId = null;

    public ?string $authorizationState = null;

    public ?string $authorizationVerifier = null;

    public ?string $authorizationClientId = null;

    public ?string $authorizationRedirectUri = null;

    public ?DateTimeInterface $authorizationExpiresAt = null;
}
