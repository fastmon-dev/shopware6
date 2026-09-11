<?php declare(strict_types=1);

namespace Fastmon\Collector\Api;

/**
 * Proof Key for Code Exchange (RFC 7636), the only thing that authenticates this shop's
 * code exchange.
 *
 * A plugin ships as readable code on the merchant's own server, so it can hold no client
 * secret - fastmon registers it as a public client and accepts `none` for client
 * authentication. What stands in the secret's place is this: the shop keeps a random
 * verifier, sends only its SHA-256 hash to the authorization endpoint, and presents the
 * verifier when it redeems the code. An intercepted authorization code is worthless
 * without it.
 *
 * S256 only. `plain` proves nothing once the challenge itself is observable, and fastmon
 * accepts nothing else.
 */
final class Pkce
{
    /**
     * 32 bytes, which is 43 base64url characters - the RFC's minimum length and the
     * maximum entropy that fits it.
     */
    private const VERIFIER_BYTES = 32;

    public static function verifier(): string
    {
        return self::base64Url(random_bytes(self::VERIFIER_BYTES));
    }

    public static function challenge(string $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier, true));
    }

    /** Base64 without padding and with the URL-safe alphabet, per RFC 7636 §A. */
    private static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
