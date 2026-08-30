<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\Collection\DomainCheckResult;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The probe reports reason codes and the administration turns them into sentences. That
 * split is what keeps the one merchant-facing text produced by PHP out of the English
 * ghetto - and it introduces a seam that can silently come apart: adding a REASON_*
 * without its snippet renders the raw key on screen, in both languages, and nothing else
 * would notice.
 */
class SnippetsCoverReasonCodesTest extends TestCase
{
    private const LOCALES = ['de-DE', 'en-GB'];

    public function testEveryReasonCodeHasASnippetInEveryLocale(): void
    {
        $codes = $this->reasonCodes();

        self::assertNotEmpty($codes, 'the reason codes should be discoverable by reflection');

        foreach (self::LOCALES as $locale) {
            $reasons = $this->section($locale, 'fastmon-collector', 'collection', 'reason');

            foreach ($codes as $code) {
                self::assertArrayHasKey(
                    $code,
                    $reasons,
                    sprintf('%s has no text for the reason code "%s"', $locale, $code)
                );
                self::assertIsString($reasons[$code]);
                self::assertNotSame('', trim($reasons[$code]));
            }
        }
    }

    public function testNoSnippetDescribesAReasonThatNoLongerExists(): void
    {
        // The other direction: a leftover text is dead weight that reads like a supported
        // case, and it is the shape a rename leaves behind.
        $codes = $this->reasonCodes();

        foreach (self::LOCALES as $locale) {
            $reasons = $this->section($locale, 'fastmon-collector', 'collection', 'reason');
            self::assertSame([], array_diff(array_keys($reasons), $codes), 'stale reason snippets in ' . $locale);
        }
    }

    public function testTheTwoLocalesDescribeTheSameCases(): void
    {
        $de = array_keys($this->section('de-DE', 'fastmon-collector', 'collection', 'reason'));
        $en = array_keys($this->section('en-GB', 'fastmon-collector', 'collection', 'reason'));

        sort($de);
        sort($en);

        self::assertSame($de, $en);
    }

    /**
     * @return list<string>
     */
    private function reasonCodes(): array
    {
        $codes = [];

        foreach ((new ReflectionClass(DomainCheckResult::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'REASON_') && \is_string($value)) {
                $codes[] = $value;
            }
        }

        return $codes;
    }

    /**
     * @return array<mixed>
     */
    private function snippets(string $locale): array
    {
        $path = __DIR__ . '/../../src/Resources/app/administration/src/snippet/' . $locale . '.json';

        self::assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * One nested section of a snippet file, asserted to exist on the way down.
     *
     * @return array<mixed>
     */
    private function section(string $locale, string ...$path): array
    {
        $node = $this->snippets($locale);

        foreach ($path as $key) {
            $child = $node[$key] ?? null;
            self::assertIsArray($child, $locale . ' has no section ' . implode('.', $path));
            $node = $child;
        }

        return $node;
    }
}
