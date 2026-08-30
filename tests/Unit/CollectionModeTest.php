<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\Collection\CollectionMode;
use PHPUnit\Framework\TestCase;

class CollectionModeTest extends TestCase
{
    public function testOnlyTheFastmonDefaultNeedsNothingProven(): void
    {
        // The other two depend on the merchant's own web server, and applying one before
        // it forwards the paths makes every tracker post into a 404.
        self::assertFalse(CollectionMode::FASTMON->needsProof());
        self::assertTrue(CollectionMode::CUSTOM->needsProof());
        self::assertTrue(CollectionMode::RELATIVE->needsProof());
    }

    public function testAnUnreadableStoredValueFallsBackToTheModeThatAlwaysWorks(): void
    {
        // A value that cannot be parsed must not leave the storefront pointing at a host
        // nobody serves. fastmon's collector is the one that needs no infrastructure.
        foreach ([null, '', 'nonsense', 42, ['relative']] as $value) {
            self::assertSame(CollectionMode::FASTMON, CollectionMode::fromConfigValue($value));
        }
    }

    public function testKnownValuesRoundTrip(): void
    {
        foreach (CollectionMode::cases() as $mode) {
            self::assertSame($mode, CollectionMode::fromConfigValue($mode->value));
        }
    }
}
