<?php declare(strict_types=1);

namespace Fastmon\Collector\Api;

/**
 * The organization has not passed fastmon's review yet (HTTP 403,
 * `organization_not_approved`).
 *
 * Its own exception because it is not a failure and not a misconfiguration: fastmon gates
 * data ingestion behind an anti-abuse review, so a freshly registered organization
 * connects fine and then cannot create an application until it is approved - by an admin,
 * by a partner provisioning it, or by a first successful payment.
 *
 * Told apart from a generic error so the admin module can say "waiting for approval"
 * instead of showing a red message next to a button the merchant will now press again.
 */
class FastmonOrganizationNotApprovedException extends FastmonApiException
{
}
