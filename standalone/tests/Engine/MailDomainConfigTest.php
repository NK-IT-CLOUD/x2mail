<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Tests\Engine;

use PHPUnit\Framework\TestCase;
use X2Mail\Standalone\Config\MailSettings;
use X2Mail\Standalone\Engine\MailDomainConfig;

class MailDomainConfigTest extends TestCase
{
    public function testOauthOnlyAgainstOneHost(): void
    {
        $c = MailDomainConfig::build(new MailSettings('mail.example.org', 993, 587, 4190));

        self::assertSame(['mail.example.org', 993, 1], [$c['IMAP']['host'], $c['IMAP']['port'], $c['IMAP']['type']]);
        self::assertSame(['mail.example.org', 587, 2], [$c['SMTP']['host'], $c['SMTP']['port'], $c['SMTP']['type']]);
        self::assertSame(['mail.example.org', 4190, 2, true], [$c['Sieve']['host'], $c['Sieve']['port'], $c['Sieve']['type'], $c['Sieve']['enabled']]);
        foreach (['IMAP', 'SMTP', 'Sieve'] as $p) {
            self::assertSame(['OAUTHBEARER', 'XOAUTH2'], $c[$p]['sasl']);
            self::assertTrue($c[$p]['ssl']['verify_peer']);
            self::assertTrue($c[$p]['ssl']['verify_peer_name']);
            self::assertFalse($c[$p]['ssl']['allow_self_signed']);
        }
    }
}
