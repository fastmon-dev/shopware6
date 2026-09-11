<?php declare(strict_types=1);

namespace Fastmon\Collector\Connection\Storage;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityProtection\EntityProtectionCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityProtection\ReadProtection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityProtection\WriteProtection;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * The shop's fastmon connection as its own table: the registration, the tokens, who
 * approved it, and the authorization in flight. `ConnectionStore` carries why none of
 * that is a setting, and why the two hashes the storefront renders stay in
 * `system_config`.
 *
 * A shop has one connection, so the table holds one row under a fixed id: every write is
 * an upsert, every read a lookup by primary key.
 *
 * Both protections admit the system scope only. The plugin reads and writes the row on
 * the shop's behalf, never on a user's, so no privilege makes the tokens listable through
 * `/api/fastmon-collector-connection`. The plugin's own admin routes report whether a
 * credential exists and what it may do, and that stays the only window.
 *
 * A classic definition rather than an attribute entity, the one place this plugin departs
 * from attributes everywhere, for two reasons that hold on every supported release: the
 * `#[Protection]` attribute takes write scopes only, so the read protection above, which
 * is what keeps the tokens out of the entity API, has no attribute form (checked on
 * 6.7.8.1 and 6.7.13.1); and attribute entities exist from 6.6.3.0, while `composer.json`
 * admits 6.6.0.
 */
final class ConnectionDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'fastmon_collector_connection';

    /** The one row. Fixed, so a write never has to look up which row it means. */
    public const ROW_ID = 'a7f3c1e2b5d94f0e8c6a2b1d3e4f5a6b';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return ConnectionCollection::class;
    }

    public function getEntityClass(): string
    {
        return ConnectionEntity::class;
    }

    protected function defineProtections(): EntityProtectionCollection
    {
        return new EntityProtectionCollection([
            new ReadProtection(Context::SYSTEM_SCOPE),
            new WriteProtection(Context::SYSTEM_SCOPE),
        ]);
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),

            // The registration. Public by design and kept across a disconnect, see
            // ConnectionStore.
            new StringField('client_id', 'clientId'),
            new LongTextField('redirect_uri', 'redirectUri'),

            // The app connection: a short-lived access token and the refresh token that
            // buys the next one.
            new LongTextField('access_token', 'accessToken'),
            new DateTimeField('access_token_expires_at', 'accessTokenExpiresAt'),
            new LongTextField('refresh_token', 'refreshToken'),
            new StringField('scopes', 'scopes'),

            // The pasted-key fallback. Never stored beside an app connection.
            new LongTextField('manual_token', 'manualToken'),

            // Who approved the connection and what it points at.
            new StringField('account_email', 'accountEmail'),
            new StringField('account_name', 'accountName'),
            new StringField('organization_id', 'organizationId'),
            new StringField('organization_name', 'organizationName'),
            new StringField('application_id', 'applicationId'),

            // The authorization in flight, while the merchant is away at fastmon. The
            // verifier lives here and nowhere else; see OAuthSession.
            new StringField('authorization_state', 'authorizationState'),
            new StringField('authorization_verifier', 'authorizationVerifier'),
            new StringField('authorization_client_id', 'authorizationClientId'),
            new LongTextField('authorization_redirect_uri', 'authorizationRedirectUri'),
            new DateTimeField('authorization_expires_at', 'authorizationExpiresAt'),

            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
