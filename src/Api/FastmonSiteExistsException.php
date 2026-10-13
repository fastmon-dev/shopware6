<?php declare(strict_types=1);

namespace Fastmon\Collector\Api;

/**
 * The domain is already a Site of this application (HTTP 409, `site_already_exists`).
 *
 * Not a failure from the plugin's side: the sync asks fastmon for every storefront
 * domain it does not see in the list, and a domain that was added between the list and
 * the create, or that fastmon normalised to the same name as one it had, answers with
 * this. The caller counts it as present and moves on.
 */
final class FastmonSiteExistsException extends FastmonApiException
{
}
