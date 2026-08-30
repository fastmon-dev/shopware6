<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\Connection\DeviceAuthorizationSession;
use Fastmon\Collector\Service\ConfigResolver;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class DeviceAuthorizationSessionTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $stored = [];

    public function testAHandleResolvesBackToTheDeviceCode(): void
    {
        // The whole point of the store: `start()` and `resolve()` happen in two separate
        // HTTP requests, so anything that does not outlive a request is useless here.
        // The object cache was the first choice and failed exactly there - a shop may
        // back it with an array adapter, or with APCu, which is per PHP-FPM worker.
        $session = $this->session();

        $handle = $session->start('dev-123', 900);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $handle);
        self::assertSame('dev-123', $session->resolve($handle));
    }

    public function testAForeignHandleResolvesToNothing(): void
    {
        $session = $this->session();
        $session->start('dev-123', 900);

        self::assertNull($session->resolve(str_repeat('a', 32)));
    }

    public function testAMalformedHandleIsRejectedWithoutALookup(): void
    {
        $session = $this->session();
        $session->start('dev-123', 900);

        self::assertNull($session->resolve('../../etc/passwd'));
        self::assertNull($session->resolve(''));
    }

    public function testStartNeverStoresSomethingAlreadyDead(): void
    {
        // A floor on the lifetime, so a backend answering with a nonsensical `expires_in`
        // cannot produce an authorization that is expired the moment it is written.
        $session = $this->session();
        $handle = $session->start('dev-123', -120);

        self::assertSame('dev-123', $session->resolve($handle));
    }

    public function testAnExpiredAuthorizationIsGoneAndCleansUpAfterItself(): void
    {
        // Seeded directly, because `start()` deliberately cannot produce this state.
        $handle = str_repeat('b', 32);
        $this->stored[ConfigResolver::DOMAIN . 'deviceAuthorization'] = json_encode([
            'handle' => $handle,
            'deviceCode' => 'dev-123',
            'expiresAt' => time() - 1,
        ], \JSON_THROW_ON_ERROR);

        self::assertNull($this->session()->resolve($handle));
        self::assertSame([], $this->stored, 'an expired entry must not linger in system_config');
    }

    public function testFinishingRemovesTheAuthorization(): void
    {
        $session = $this->session();
        $handle = $session->start('dev-123', 900);

        $session->finish($handle);

        self::assertNull($session->resolve($handle));
        self::assertSame([], $this->stored);
    }

    public function testAStalePollCannotWipeANewerAttempt(): void
    {
        // Someone abandons a connect, starts another, and the first tab polls once more.
        // That must not take the live authorization with it.
        $session = $this->session();
        $old = $session->start('dev-old', 900);
        $new = $session->start('dev-new', 900);

        $session->finish($old);

        self::assertSame('dev-new', $session->resolve($new));
    }

    public function testStartingAgainReplacesTheAbandonedAttempt(): void
    {
        $session = $this->session();
        $first = $session->start('dev-old', 900);
        $session->start('dev-new', 900);

        self::assertNull($session->resolve($first));
    }

    public function testAbandonDropsWhateverIsInFlight(): void
    {
        $session = $this->session();
        $handle = $session->start('dev-123', 900);

        $session->abandon();

        self::assertNull($session->resolve($handle));
    }

    private function session(): DeviceAuthorizationSession
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(fn (string $k): mixed => $this->stored[$k] ?? null);
        $systemConfig->method('set')->willReturnCallback(function (string $k, mixed $v): void {
            $this->stored[$k] = $v;
        });
        $systemConfig->method('delete')->willReturnCallback(function (string $k): void {
            unset($this->stored[$k]);
        });

        return new DeviceAuthorizationSession($systemConfig);
    }
}
