<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use DateTimeImmutable;
use DateTimeInterface;
use Fastmon\Collector\Connection\OAuthSession;
use Fastmon\Collector\Tests\Unit\Fake\StoresConnection;
use PHPUnit\Framework\TestCase;

class OAuthSessionTest extends TestCase
{
    use StoresConnection;

    public function testAStateResolvesBackToWhatTheCodeHasToBeRedeemedWith(): void
    {
        // The whole point of storing this: `start()` and `resolve()` happen in two
        // separate HTTP requests, with a visit to fastmon in between. The object cache
        // was the first choice and failed exactly there - a shop may back it with an
        // array adapter, or with APCu, which is per PHP-FPM worker.
        $session = $this->session();

        $attempt = $session->start('dyn_1', 'https://shop.example.com/admin');
        $resolved = $session->resolve($attempt['state']);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $attempt['state']);
        self::assertNotNull($resolved);
        self::assertSame($attempt['verifier'], $resolved->verifier);
        self::assertSame('dyn_1', $resolved->clientId);
        self::assertSame('https://shop.example.com/admin', $resolved->redirectUri);
    }

    public function testAnAttemptIsTypedColumnsInTheConnectionRow(): void
    {
        // Not a JSON blob in a configuration value, and not in `system_config` at all:
        // an authorization in flight is internal state, and writing it there would drop
        // the shop's page cache every time somebody presses Connect.
        $session = $this->session();

        $attempt = $session->start('dyn_1', 'https://shop.example.com/admin');

        self::assertSame($attempt['state'], $this->row['authorizationState']);
        self::assertSame($attempt['verifier'], $this->row['authorizationVerifier']);
        self::assertSame('dyn_1', $this->row['authorizationClientId']);
        self::assertSame('https://shop.example.com/admin', $this->row['authorizationRedirectUri']);
        self::assertInstanceOf(DateTimeInterface::class, $this->row['authorizationExpiresAt']);
        self::assertSame([], $this->config, 'an attempt in flight has no business in system_config');
    }

    public function testTheVerifierIsAFreshPkceSecretEveryTime(): void
    {
        // It is the only thing authenticating the code exchange - this shop is a public
        // client with no secret - so a reused or short verifier would be the whole
        // protection gone.
        $session = $this->session();

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
        $session = $this->session();
        $attempt = $session->start('dyn_1', 'https://old.example.com/backend');

        self::assertSame('https://old.example.com/backend', $session->resolve($attempt['state'])?->redirectUri);
    }

    public function testAForeignStateResolvesToNothing(): void
    {
        $session = $this->session();
        $session->start('dyn_1', 'https://shop.example.com/admin');

        self::assertNull($session->resolve(str_repeat('a', 32)));
    }

    public function testAMalformedStateIsRejectedWithoutALookup(): void
    {
        $session = $this->session();
        $session->start('dyn_1', 'https://shop.example.com/admin');

        self::assertNull($session->resolve('../../etc/passwd'));
        self::assertNull($session->resolve(''));
    }

    public function testAnExpiredAttemptIsGoneAndCleansUpAfterItself(): void
    {
        // Seeded directly, because `start()` deliberately cannot produce this state.
        $state = str_repeat('b', 32);
        $this->row = [
            'authorizationState' => $state,
            'authorizationVerifier' => 'v',
            'authorizationClientId' => 'dyn_1',
            'authorizationRedirectUri' => 'https://shop.example.com/admin',
            'authorizationExpiresAt' => new DateTimeImmutable('@' . (time() - 1)),
        ];

        $store = $this->connectionStore();

        self::assertNull((new OAuthSession($store))->resolve($state));
        self::assertNull($store->authorization(), 'an expired attempt must not linger');
    }

    public function testFinishingRemovesTheAttempt(): void
    {
        // The code is single-use, so an attempt left open is one an intercepted code
        // could still be redeemed against.
        $session = $this->session();
        $attempt = $session->start('dyn_1', 'https://shop.example.com/admin');

        $session->finish($attempt['state']);

        self::assertNull($session->resolve($attempt['state']));
        self::assertNull($this->row['authorizationVerifier']);
    }

    public function testALateCallbackCannotWipeANewerAttempt(): void
    {
        // Someone abandons a connect, starts another, and the first tab comes back. That
        // must not take the live attempt with it.
        $session = $this->session();
        $old = $session->start('dyn_1', 'https://shop.example.com/admin');
        $new = $session->start('dyn_1', 'https://shop.example.com/admin');

        $session->finish($old['state']);

        self::assertNotNull($session->resolve($new['state']));
    }

    public function testStartingAgainReplacesTheAbandonedAttempt(): void
    {
        $session = $this->session();
        $first = $session->start('dyn_1', 'https://shop.example.com/admin');
        $session->start('dyn_1', 'https://shop.example.com/admin');

        self::assertNull($session->resolve($first['state']));
    }

    public function testAbandonDropsWhateverIsInFlight(): void
    {
        $session = $this->session();
        $attempt = $session->start('dyn_1', 'https://shop.example.com/admin');

        $session->abandon();

        self::assertNull($session->resolve($attempt['state']));
    }

    private function session(): OAuthSession
    {
        return new OAuthSession($this->connectionStore());
    }
}
