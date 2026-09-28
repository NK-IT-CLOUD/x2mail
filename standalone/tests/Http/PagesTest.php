<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Tests\Http;

use PHPUnit\Framework\TestCase;
use X2Mail\Standalone\Http\Pages;

class PagesTest extends TestCase
{
    private function assertStyledPage(string $html, string $heading, string $buttonHref): void
    {
        self::assertStringContainsString('<html lang="de">', $html);
        self::assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $html);
        self::assertStringContainsString('/x2mail/v/current/static/logo-512.png', $html);
        self::assertStringContainsString($heading, $html);
        self::assertStringContainsString('href="' . $buttonHref . '"', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('http://', $html);
        self::assertStringNotContainsString('https://', $html);
    }

    public function testForbidden(): void
    {
        $html = Pages::forbidden();
        $this->assertStyledPage($html, 'Kein Zugang', '/oidc/logout');
        self::assertStringContainsString('Abmelden', $html);
        self::assertStringContainsString('nicht freigeschaltet', $html);
    }

    public function testExpired(): void
    {
        $html = Pages::expired();
        $this->assertStyledPage($html, 'Anmeldung abgelaufen', '/oidc/login');
        self::assertStringContainsString('Erneut anmelden', $html);
        self::assertStringContainsString('abgelaufen', $html);
    }

    public function testUnavailable(): void
    {
        $html = Pages::unavailable();
        $this->assertStyledPage($html, 'Anmeldung nicht erreichbar', '/oidc/login');
        self::assertStringContainsString('Erneut versuchen', $html);
    }
}
