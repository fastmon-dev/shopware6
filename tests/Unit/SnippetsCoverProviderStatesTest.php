<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\ServerTiming\TidewaysLayerMetricsProvider;
use PHPUnit\Framework\TestCase;

/**
 * The same seam as the probe reason codes: providers report a state, the administration
 * turns it into a sentence, and a state without a snippet renders its raw key on screen.
 *
 * The states are asserted as a fixed set rather than discovered, because they come out of
 * `match`-style methods that reflection cannot enumerate - so this doubles as the list of
 * what a provider is allowed to report.
 */
class SnippetsCoverProviderStatesTest extends TestCase
{
    private const STATES = ['active', 'outdated', 'absent'];
    private const LOCALES = ['de-DE', 'en-GB'];

    public function testEveryStateHasASnippetInEveryLocale(): void
    {
        foreach (self::LOCALES as $locale) {
            $states = $this->snippets($locale)['fastmon-collector']['serverTiming']['state'] ?? [];

            self::assertIsArray($states);

            foreach (self::STATES as $state) {
                self::assertArrayHasKey($state, $states, $locale . ' is missing the state "' . $state . '"');
                self::assertNotSame('', trim((string) $states[$state]));
            }

            self::assertSame([], array_diff(array_keys($states), self::STATES), 'stale state snippets in ' . $locale);
        }
    }

    public function testTheProvidersOnlyReportStatesWeCanRender(): void
    {
        // Whatever this host happens to have installed, the reported state has to be one
        // the administration knows a sentence for.
        foreach ([new TidewaysLayerMetricsProvider()] as $provider) {
            self::assertContains($provider->state(), self::STATES, $provider->name());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snippets(string $locale): array
    {
        $decoded = json_decode(
            (string) file_get_contents(
                __DIR__ . '/../../src/Resources/app/administration/src/snippet/' . $locale . '.json'
            ),
            true,
            512,
            \JSON_THROW_ON_ERROR
        );

        self::assertIsArray($decoded);

        return $decoded;
    }
}
