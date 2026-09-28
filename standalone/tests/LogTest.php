<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Tests;

use PHPUnit\Framework\TestCase;
use X2Mail\Standalone\Log;

class LogTest extends TestCase
{
    public function testControlCharactersAreStripped(): void
    {
        $lines = [];
        $log = new Log(function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $log->warning("a\nb\x07c");

        self::assertCount(1, $lines);
        self::assertSame('[x2mail-webmail] WARNING: abc', $lines[0]);
    }

    public function testTabIsReplacedWithSpace(): void
    {
        $lines = [];
        $log = new Log(function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $log->info("a\tb");

        self::assertSame('[x2mail-webmail] INFO: a b', $lines[0]);
    }
}
