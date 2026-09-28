<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Tests\Http;

use PHPUnit\Framework\TestCase;
use X2Mail\Standalone\Config\ConfigException;
use X2Mail\Standalone\Http\ErrorResponses;
use X2Mail\Standalone\Http\Pages;
use X2Mail\Standalone\Log;
use X2Mail\Standalone\Oidc\OidcException;

class ErrorResponsesTest extends TestCase
{
    /** @var list<string> */
    private array $logLines = [];

    private function log(): Log
    {
        return new Log(function (string $line): void {
            $this->logLines[] = $line;
        });
    }

    public function testConfigExceptionBecomes500WithoutLeakingDetails(): void
    {
        $r = ErrorResponses::for(new ConfigException('secret file /etc/x2mail-webmail/webmail.toml unreadable'), $this->log());

        self::assertSame(500, $r['status']);
        self::assertSame('no-store', $r['headers']['Cache-Control']);
        self::assertStringNotContainsString('/etc/x2mail-webmail', $r['body']);
        self::assertStringNotContainsString('unreadable', $r['body']);
    }

    public function testOidcExceptionBecomes503WithUnavailablePage(): void
    {
        $r = ErrorResponses::for(new OidcException('discovery request failed: connection refused to 192.0.2.22'), $this->log());

        self::assertSame(503, $r['status']);
        self::assertSame('no-store', $r['headers']['Cache-Control']);
        self::assertSame(Pages::unavailable(), $r['body']);
        self::assertStringNotContainsString('192.0.2.22', $r['body']);
    }

    public function testOtherThrowableBecomes500WithoutLeakingStackTrace(): void
    {
        $r = ErrorResponses::for(
            new \RuntimeException('session could not be started at /opt/x2mail/standalone/src/Session/PhpSession.php:20'),
            $this->log(),
        );

        self::assertSame(500, $r['status']);
        self::assertSame('no-store', $r['headers']['Cache-Control']);
        self::assertStringNotContainsString('PhpSession.php', $r['body']);
        self::assertStringNotContainsString('session could not be started', $r['body']);
        self::assertStringContainsString('RuntimeException: session could not be started', \implode("\n", $this->logLines));
    }
}
