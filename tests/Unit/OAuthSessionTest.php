<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\Connection\OAuthSession;
use Fastmon\Collector\Tests\Unit\Fake\StoresSystemConfig;
use PHPUnit\Framework\TestCase;

class OAuthSessionTest extends TestCase
{
    use StoresSystemConfig;

    public function testAStateResolvesBackToWhatTheCodeHasToBeRedeemedWith(): void
    {
        // The whole point of the store: `start()` and `resolve()` happen in two separate
        // HTTP requests, with a visit to fastmon in between. The object cache was the
        // first choice and failed exactly there - a shop may back it with an array
        // adapter, or with APCu, which is per PHP-FPM worker.
        $session = new OAuthSession($this->systemConfig());

        $attempt = $session->start('dyn_1', 'https://shop.example.com/admin');

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $attempt['state']);
        self::assertSame([
            'verifier' => $attempt['verifier'],
            'clientId' => 'dyn_1',
            'redirectUri' => 'https://shop.example.com/admin',
        ], $session->resolve($attempt['state']));
    }

    public function testTheVerifierIsAFreshPkceSecretEveryTime(): void
    {
        // It is the only thing authenticating the code exchange - this shop is a public
        // client with no secret - so a reused or short verifier would be the whole
        // protection gone.
        $session = new OAuthSession($this->systemConfig());

        $first = $session->start('dyn_1', 'https://shop.example.com/admin')['verifier'];
        $second = $session->start('dyn_1', 'https://shop.example.com/admin')['verifier'];

        self::assertNotSame($first, $second);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9\-_]{43,128}$/', $first);
    }

    public function testTheRedirectUriIsRememberedRatherThanRecomputed(): void
    {
        // fastmon binds the code to exactly what the authorization request carried. If
        // the shop's address changed in between, recomputing it at exchange time would
        // produce a mismatch nobody could read.
        $session = new OAuthSession($this->systemConfig());
        $attempt = $session->start('dyn_1', 'https://old.example.com/backend');

        self::assertSame(
            'https://old.example.com/backend',
            ($session->resolve($attempt['state']) ?? [])['redirectUri'] ?? null
        );
    }

    public function testAForeignStateResolvesToNothing(): void
    {
        $session = new OAuthSession($this->systemConfig());
        $session->start('dyn_1', 'https://shop.example.com/admin');

        self::assertNull($session->resolve(str_repeat('a', 32)));
    }

    public function testAMalformedStateIsRejectedWithoutALookup(): void
    {
        $session = new OAuthSession($this->systemConfig());
        $session->start('dyn_1', 'https://shop.example.com/admin');

        self::assertNull($session->resolve('../../etc/passwd'));
        self::assertNull($session->resolve(''));
    }

    public function testAnExpiredAttemptIsGoneAndCleansUpAfterItself(): void
    {
        // Seeded directly, because `start()` deliberately cannot produce this state.
        $state = str_repeat('b', 32);
        $this->stored[OAuthSession::KEY] = json_encode([
            'state' => $state,
            'verifier' => 'v',
            'clientId' => 'dyn_1',
            'redirectUri' => 'https://shop.example.com/admin',
            'expiresAt' => time() - 1,
        ], \JSON_THROW_ON_ERROR);

        self::assertNull((new OAuthSession($this->systemConfig()))->resolve($state));
        self::assertSame([], $this->stored, 'an expired attempt must not linger in system_config');
    }

    public function testFinishingRemovesTheAttempt(): void
    {
        // The code is single-use, so an attempt left open is one an intercepted code
        // could still be redeemed against.
        $session = new OAuthSession($this->systemConfig());
        $attempt = $session->start('dyn_1', 'https://shop.example.com/admin');

        $session->finish($attempt['state']);

        self::assertNull($session->resolve($attempt['state']));
        self::assertSame([], $this->stored);
    }

    public function testALateCallbackCannotWipeANewerAttempt(): void
    {
        // Someone abandons a connect, starts another, and the first tab comes back. That
        // must not take the live attempt with it.
        $session = new OAuthSession($this->systemConfig());
        $old = $session->start('dyn_1', 'https://shop.example.com/admin');
        $new = $session->start('dyn_1', 'https://shop.example.com/admin');

        $session->finish($old['state']);

        self::assertNotNull($session->resolve($new['state']));
    }

    public function testStartingAgainReplacesTheAbandonedAttempt(): void
    {
        $session = new OAuthSession($this->systemConfig());
        $first = $session->start('dyn_1', 'https://shop.example.com/admin');
        $session->start('dyn_1', 'https://shop.example.com/admin');

        self::assertNull($session->resolve($first['state']));
    }

    public function testAbandonDropsWhateverIsInFlight(): void
    {
        $session = new OAuthSession($this->systemConfig());
        $attempt = $session->start('dyn_1', 'https://shop.example.com/admin');

        $session->abandon();

        self::assertNull($session->resolve($attempt['state']));
    }
}
