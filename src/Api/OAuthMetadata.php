<?php declare(strict_types=1);

namespace Fastmon\Collector\Api;

/**
 * The authorization server metadata (RFC 8414) fastmon publishes at
 * `/.well-known/oauth-authorization-server`.
 *
 * Every endpoint the connect flow uses is read from here rather than assembled from a
 * base URL, because fastmon's own documentation names this document as the contract and
 * because the OAuth routes do not share the `/v1` prefix the rest of the API is reached
 * through. A plugin in the wild cannot be redeployed when a path moves; a document it
 * fetches can say so.
 *
 * `revocationEndpoint` is the one field allowed to be empty: an instance that does not
 * advertise one simply cannot be told about a disconnect, which costs the merchant
 * nothing they can see.
 */
final readonly class OAuthMetadata
{
    public function __construct(
        public string $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public string $registrationEndpoint,
        public string $revocationEndpoint,
    ) {
    }
}
