<?php declare(strict_types=1);

namespace Fastmon\Collector\Tests\Unit;

use Fastmon\Collector\ServerTiming\ServerIdentity;
use PHPUnit\Framework\TestCase;

class ServerIdentityTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['FASTMON_SERVER_NAME']);
    }

    public function testTheHostnameIsTheDefault(): void
    {
        // It has to come from the machine: plugin settings live in the database every
        // node of the cluster shares, so a configured name would be identical everywhere
        // - the opposite of what this dimension is for.
        $name = (new ServerIdentity())->name();

        self::assertNotSame('', $name);
        self::assertSame(mb_substr((string) gethostname(), 0, 32), $name);
    }

    public function testTheEnvironmentOverridesTheHostname(): void
    {
        // Per node the way a database row can never be.
        $_SERVER['FASTMON_SERVER_NAME'] = '  web-01  ';

        self::assertSame('web-01', (new ServerIdentity())->name());
    }

    public function testTheValueIsTrimmedToWhatTheCollectorAccepts(): void
    {
        // fastmon caps `desc` at 32 characters. Sending more means the entry is dropped
        // on arrival, and "configured but never shows up" is a bad afternoon.
        $_SERVER['FASTMON_SERVER_NAME'] = str_repeat('a', 60);

        self::assertSame(32, mb_strlen((new ServerIdentity())->name()));
    }

    public function testCharactersTheCollectorRejectsBecomeDashes(): void
    {
        // `web01.fra.internal` should stay readable, and one stray character should not
        // cost the whole entry.
        foreach ([
            'web01.fra.internal' => 'web01.fra.internal',
            'web-01#a' => 'web-01-a',
            'node+1' => 'node-1',
            'web_01:8080' => 'web_01:8080',
        ] as $configured => $expected) {
            $_SERVER['FASTMON_SERVER_NAME'] = (string) $configured;

            self::assertSame($expected, (new ServerIdentity())->name());
        }
    }
}
