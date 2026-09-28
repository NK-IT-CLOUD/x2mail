<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Tests\Engine;

use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;

/**
 * X-CRYPTO (KAddressBook) carries its data in parameters. Every contact the
 * engine saves has it, so parsing must stay silent and keep the parameters.
 */
class XCryptoPropertyTest extends TestCase
{
    private const VCARD = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:abc\r\nFN:Alice\r\n"
        . "X-CRYPTO;ALLOWED=PGP/MIME;SIGN=always:\r\nEND:VCARD\r\n";

    private string $logFile;
    private string|false $previousLog;

    protected function setUp(): void
    {
        $this->logFile = (string) \tempnam(\sys_get_temp_dir(), 'x2w-xcrypto');
        $this->previousLog = \ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        \ini_set('error_log', (string) $this->previousLog);
        @\unlink($this->logFile);
    }

    public function testParsingWritesNothingToTheErrorLog(): void
    {
        Reader::read(self::VCARD);

        self::assertSame('', (string) \file_get_contents($this->logFile));
    }

    public function testParametersSurviveARoundTrip(): void
    {
        $out = Reader::read(self::VCARD)->serialize();

        self::assertStringContainsString('X-CRYPTO;ALLOWED=PGP/MIME;SIGN=always:', $out);
    }
}
