<?php declare(strict_types=1);

namespace Fastmon\Collector\Connection\Storage;

use DateTimeInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

/**
 * One row of `fastmon_collector_connection`, as the DAL hydrates it.
 *
 * Protected fields behind getters and setters, which is what `bin/console dal:validate`
 * checks every entity for. Nothing outside `ConnectionStore` sees this class: callers get
 * the `Connection` and `Credentials` value objects, which know what an empty column means.
 *
 * One field per column, so it is as wide as the connection is. Splitting it would mean
 * splitting the row.
 *
 * @SuppressWarnings("PHPMD.TooManyFields")
 */
final class ConnectionEntity extends Entity
{
    use EntityIdTrait;

    protected ?string $clientId = null;

    protected ?string $redirectUri = null;

    protected ?string $accessToken = null;

    protected ?DateTimeInterface $accessTokenExpiresAt = null;

    protected ?string $refreshToken = null;

    protected ?string $scopes = null;

    protected ?string $manualToken = null;

    protected ?string $accountEmail = null;

    protected ?string $accountName = null;

    protected ?string $organizationId = null;

    protected ?string $organizationName = null;

    protected ?string $applicationId = null;

    protected ?string $authorizationState = null;

    protected ?string $authorizationVerifier = null;

    protected ?string $authorizationClientId = null;

    protected ?string $authorizationRedirectUri = null;

    protected ?DateTimeInterface $authorizationExpiresAt = null;

    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    public function setClientId(?string $clientId): void
    {
        $this->clientId = $clientId;
    }

    public function getRedirectUri(): ?string
    {
        return $this->redirectUri;
    }

    public function setRedirectUri(?string $redirectUri): void
    {
        $this->redirectUri = $redirectUri;
    }

    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    public function setAccessToken(?string $accessToken): void
    {
        $this->accessToken = $accessToken;
    }

    public function getAccessTokenExpiresAt(): ?DateTimeInterface
    {
        return $this->accessTokenExpiresAt;
    }

    public function setAccessTokenExpiresAt(?DateTimeInterface $accessTokenExpiresAt): void
    {
        $this->accessTokenExpiresAt = $accessTokenExpiresAt;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function setRefreshToken(?string $refreshToken): void
    {
        $this->refreshToken = $refreshToken;
    }

    public function getScopes(): ?string
    {
        return $this->scopes;
    }

    public function setScopes(?string $scopes): void
    {
        $this->scopes = $scopes;
    }

    public function getManualToken(): ?string
    {
        return $this->manualToken;
    }

    public function setManualToken(?string $manualToken): void
    {
        $this->manualToken = $manualToken;
    }

    public function getAccountEmail(): ?string
    {
        return $this->accountEmail;
    }

    public function setAccountEmail(?string $accountEmail): void
    {
        $this->accountEmail = $accountEmail;
    }

    public function getAccountName(): ?string
    {
        return $this->accountName;
    }

    public function setAccountName(?string $accountName): void
    {
        $this->accountName = $accountName;
    }

    public function getOrganizationId(): ?string
    {
        return $this->organizationId;
    }

    public function setOrganizationId(?string $organizationId): void
    {
        $this->organizationId = $organizationId;
    }

    public function getOrganizationName(): ?string
    {
        return $this->organizationName;
    }

    public function setOrganizationName(?string $organizationName): void
    {
        $this->organizationName = $organizationName;
    }

    public function getApplicationId(): ?string
    {
        return $this->applicationId;
    }

    public function setApplicationId(?string $applicationId): void
    {
        $this->applicationId = $applicationId;
    }

    public function getAuthorizationState(): ?string
    {
        return $this->authorizationState;
    }

    public function setAuthorizationState(?string $authorizationState): void
    {
        $this->authorizationState = $authorizationState;
    }

    public function getAuthorizationVerifier(): ?string
    {
        return $this->authorizationVerifier;
    }

    public function setAuthorizationVerifier(?string $authorizationVerifier): void
    {
        $this->authorizationVerifier = $authorizationVerifier;
    }

    public function getAuthorizationClientId(): ?string
    {
        return $this->authorizationClientId;
    }

    public function setAuthorizationClientId(?string $authorizationClientId): void
    {
        $this->authorizationClientId = $authorizationClientId;
    }

    public function getAuthorizationRedirectUri(): ?string
    {
        return $this->authorizationRedirectUri;
    }

    public function setAuthorizationRedirectUri(?string $authorizationRedirectUri): void
    {
        $this->authorizationRedirectUri = $authorizationRedirectUri;
    }

    public function getAuthorizationExpiresAt(): ?DateTimeInterface
    {
        return $this->authorizationExpiresAt;
    }

    public function setAuthorizationExpiresAt(?DateTimeInterface $authorizationExpiresAt): void
    {
        $this->authorizationExpiresAt = $authorizationExpiresAt;
    }
}
