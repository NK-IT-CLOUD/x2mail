<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Tests\Container;

use PHPUnit\Framework\TestCase;

/**
 * The security headers must reach every response of the image: /oidc/*,
 * redirects, error pages, assets and engine pages.
 */
class NginxConfTest extends TestCase
{
    private const HEADERS = [
        'Strict-Transport-Security' => '"max-age=31536000"',
        'X-Content-Type-Options' => '"nosniff"',
        'X-Frame-Options' => '"DENY"',
        'Referrer-Policy' => '"no-referrer"',
        'X-Robots-Tag' => '"noindex, nofollow"',
        'X-Permitted-Cross-Domain-Policies' => '"none"',
    ];

    private static function conf(): string
    {
        return (string) \file_get_contents(\dirname(__DIR__, 2) . '/container/nginx.conf');
    }

    public function testServerSendsSecurityHeadersOnEveryStatus(): void
    {
        $conf = self::conf();
        foreach (self::HEADERS as $name => $value) {
            self::assertMatchesRegularExpression(
                '/^\s*add_header ' . \preg_quote($name, '/') . ' ' . \preg_quote($value, '/') . ' always;$/m',
                $conf,
                $name
            );
        }
    }

    /** nginx drops inherited add_header lines in any block that has its own. */
    public function testNoLocationOverridesTheHeaders(): void
    {
        $conf = self::conf();
        $server = \substr($conf, (int) \strpos($conf, 'server {'));
        self::assertSame(1, \preg_match('/^\s*location /m', $server, $m, \PREG_OFFSET_CAPTURE));
        self::assertDoesNotMatchRegularExpression('/^\s*add_header /m', \substr($server, $m[0][1]));
    }

    /** The engine sends some of them itself; nginx is the single source, without duplicates. */
    public function testEngineCopiesAreHidden(): void
    {
        $conf = self::conf();
        foreach (['Strict-Transport-Security', 'X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy'] as $name) {
            self::assertMatchesRegularExpression('/^\s*fastcgi_hide_header ' . \preg_quote($name, '/') . ';$/m', $conf, $name);
        }
    }
}
