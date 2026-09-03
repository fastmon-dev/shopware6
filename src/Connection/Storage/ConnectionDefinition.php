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
 * approved it, and the authorization in flight.
 *
 * ## Why a table and not `system_config`
 *
 * `system_config` is built for configuration: memoised once per request, tagged into the
 * page cache, readable through Shopware's generic system-config endpoint. Every one of
 * those is right for a setting and wrong for a token. Keeping the connection there meant
 * a raw SQL read past the memo, a flag to keep writes out of the page cache that only
 * some Shopware releases understood, and a JSON blob for the authorization in flight.
 * A row of typed columns has none of that: the repository reads what the database holds,
 * a write invalidates no page, and nothing here is a string somebody has to unwrap.
 *
 * ## One row
 *
 * A shop has one connection, so the table holds one row under a fixed id. That keeps
 * every write an upsert and every read a lookup by primary key.
 *
 * ## Not reachable through the API
 *
 * Both protections admit the system scope only. The plugin reads and writes the row on
 * the shop's behalf, never on a user's, and the tokens must not be listable through
 * `/api/fastmon-collector-connection` by anyone, whatever privileges they hold. The
 * plugin's own admin routes report whether a credential exists and what it may do, and
 * that stays the only window.
 *
 * What the storefront renders (the source and collector hashes) is deliberately not
 * here: those two are configuration in the full sense, and Shopware dropping the cached pages that
 * carry them when they change is the point. They stay in `system_config`.
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
