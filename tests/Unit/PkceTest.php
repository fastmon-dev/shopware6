<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\Api\Pkce;
use PHPUnit\Framework\TestCase;

final class PkceTest extends TestCase
{
    public function testTheChallengeMatchesTheRfc7636TestVector(): void
    {
        // RFC 7636 appendix B. Pins both details the exchange depends on: SHA-256 over
        // the ASCII verifier, and base64url without padding.
        self::assertSame(
            'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            Pkce::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk')
        );
    }

    public function testTheVerifierIsFortyThreeUnreservedCharacters(): void
    {
        // 32 random bytes: the RFC's minimum length and the most entropy that fits it.
        // Only the unreserved alphabet, so it survives a form body untouched.
        $verifier = Pkce::verifier();

        self::assertSame(43, \strlen($verifier));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $verifier);
        self::assertNotSame($verifier, Pkce::verifier());
    }
}
