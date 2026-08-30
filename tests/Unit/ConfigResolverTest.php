<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\Service\ConfigResolver;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ConfigResolverTest extends TestCase
{
    public function testServerTimingIsOnUnlessSwitchedOff(): void
    {
        // Shopware writes the config.xml defaults only on install or activate, so a shop
        // that merely pulled new files has no stored value - and `(bool) null` would turn
        // a documented "on by default" into off.
        self::assertTrue($this->resolver([])->serverTiming(null)->enabled);
        self::assertFalse($this->resolver(['serverTiming' => false])->serverTiming(null)->enabled);
    }

    public function testTheScriptBaseFollowsTheCollectionMode(): void
    {
        // Derived, never stored on its own: the mode is the single source of truth, so
        // the script can never end up on a different host than the beacon.
        self::assertSame(
            'https://fastmon.site',
            $this->resolver([])->storefront(null)->scriptBaseUrl
        );
        self::assertSame(
            '',
            $this->resolver(['collectionMode' => 'relative'])->storefront(null)->scriptBaseUrl,
            'relative has to resolve same-origin, so the base is empty'
        );
        self::assertSame(
            'https://metrics.example.com',
            $this->resolver([
                'collectionMode' => 'custom',
                'customCollectorDomain' => 'https://metrics.example.com/',
            ])->storefront(null)->scriptBaseUrl,
            'the templates append /s/<id>.js, and a pasted URL routinely has a trailing slash'
        );
    }

    public function testACustomModeWithoutADomainFallsBackToFastmon(): void
    {
        // Cannot be produced through the admin, but rendering `/s/…` against the shop
        // would be the worse failure: a 404 on every page instead of working collection.
        self::assertSame(
            'https://fastmon.site',
            $this->resolver(['collectionMode' => 'custom'])->storefront(null)->scriptBaseUrl
        );
    }

    public function testAnUnknownStoredModeFallsBackToFastmon(): void
    {
        self::assertSame(
            'https://fastmon.site',
            $this->resolver(['collectionMode' => 'nonsense'])->storefront(null)->scriptBaseUrl
        );
    }

    public function testTheApiBaseUrlIsNotASetting(): void
    {
        // One production fastmon, every shop talks to it. A stored value must not be
        // able to point the shop somewhere else - that was a footgun with no upside.
        self::assertSame(
            'https://api.fastmon.eu',
            $this->resolver(['apiBaseUrl' => 'https://evil.example'])->apiBaseUrl()
        );
    }

    public function testTheEnvironmentCanRedirectTheApiForDevelopment(): void
    {
        // The escape hatch for a local backend or a stub. Deliberately not reachable
        // from the administration.
        $_SERVER['FASTMON_API_BASE_URL'] = 'http://localhost:9500/';

        try {
            self::assertSame('http://localhost:9500', $this->resolver([])->apiBaseUrl());
        } finally {
            unset($_SERVER['FASTMON_API_BASE_URL']);
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    private function resolver(array $values): ConfigResolver
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            static fn (string $key): mixed => $values[str_replace(ConfigResolver::DOMAIN, '', $key)] ?? null
        );

        return new ConfigResolver($systemConfig);
    }
}
