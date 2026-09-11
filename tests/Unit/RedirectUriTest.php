<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\Api\OAuthUnavailableException;
use Fastmon\Collector\Connection\RedirectUri;
use PHPUnit\Framework\TestCase;

class RedirectUriTest extends TestCase
{
    /** Both superglobals, because that is where `EnvironmentHelper` looks. */
    private const KEYS = ['APP_URL', 'FASTMON_OAUTH_REDIRECT_URI'];

    /** @var array<string, array{mixed, mixed}> */
    private array $env = [];

    protected function setUp(): void
    {
        foreach (self::KEYS as $key) {
            $this->env[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null];
            unset($_SERVER[$key], $_ENV[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $key => [$server, $env]) {
            unset($_SERVER[$key], $_ENV[$key]);

            if ($server !== null) {
                $_SERVER[$key] = $server;
            }

            if ($env !== null) {
                $_ENV[$key] = $env;
            }
        }
    }

    public function testTheAdministrationsOwnAddressIsUsedWhenItIsThisShop(): void
    {
        // The browser is the only party that knows where the administration is really
        // served from - the path is not fixed, and some shops move it.
        $_SERVER['APP_URL'] = 'https://shop.example.com';

        self::assertSame(
            'https://shop.example.com/backend',
            (new RedirectUri())->resolve('https://shop.example.com/backend')
        );
    }

    public function testAnAddressOnAnotherHostIsIgnoredInFavourOfTheShopsOwn(): void
    {
        // The check that matters: without it, anyone who can already write the plugin
        // configuration could register a client whose authorization codes go to their
        // own domain.
        $_SERVER['APP_URL'] = 'https://shop.example.com';

        self::assertSame(
            'https://shop.example.com/admin',
            (new RedirectUri())->resolve('https://evil.example.net/admin')
        );
    }

    public function testAPortIsPartOfBeingTheSameShop(): void
    {
        $_SERVER['APP_URL'] = 'https://shop.example.com:8443';

        self::assertSame(
            'https://shop.example.com:8443/admin',
            (new RedirectUri())->resolve('https://shop.example.com/admin')
        );
    }

    public function testTheQueryAndFragmentOfTheCallbackAreStripped(): void
    {
        // The administration's location carries both in normal use, and fastmon refuses a
        // registration that has either.
        $_SERVER['APP_URL'] = 'https://shop.example.com';

        self::assertSame(
            'https://shop.example.com/admin',
            (new RedirectUri())->resolve('https://shop.example.com/admin/?code=x#/sw/dashboard')
        );
    }

    public function testHttpIsRefusedExceptOnLoopback(): void
    {
        // A redirect over plain http hands the authorization code to the network. The one
        // place that cannot be intercepted is the machine itself, which is also exactly
        // what fastmon accepts.
        $_SERVER['APP_URL'] = 'http://shop.example.com';

        $this->expectException(OAuthUnavailableException::class);
        (new RedirectUri())->resolve('http://shop.example.com/admin');
    }

    public function testLocalDevelopmentOverLoopbackWorks(): void
    {
        $_SERVER['APP_URL'] = 'http://localhost:8000';

        self::assertSame(
            'http://localhost:8000/admin',
            (new RedirectUri())->resolve('http://localhost:8000/admin')
        );
    }

    public function testAShopWithoutAnAppUrlSaysSoRatherThanGuessing(): void
    {
        $this->expectException(OAuthUnavailableException::class);
        (new RedirectUri())->resolve('https://shop.example.com/admin');
    }

    public function testTheEnvironmentOverrideWins(): void
    {
        // For a shop behind a proxy that rewrites the address the browser sees. A wrong
        // value here is a connection that cannot complete, so it belongs to whoever has
        // shell access rather than in a settings field.
        $_SERVER['APP_URL'] = 'https://internal.example.com';
        $_SERVER['FASTMON_OAUTH_REDIRECT_URI'] = 'https://shop.example.com/admin';

        self::assertSame(
            'https://shop.example.com/admin',
            (new RedirectUri())->resolve('https://internal.example.com/admin')
        );
    }
}
