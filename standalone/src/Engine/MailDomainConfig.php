<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Engine;

use X2Mail\Standalone\Config\MailSettings;

/**
 * Engine domain config for the one mail host. Same schema as
 * OCA\X2Mail\Service\DomainConfigService::buildDomainConfig (parity test in
 * tests/Unit/Service/StandaloneDomainParityTest.php). IMAP implicit TLS,
 * SMTP and Sieve STARTTLS; SSO-only SASL.
 */
final class MailDomainConfig
{
    private const SSL = 1;
    private const STARTTLS = 2;
    private const SASL = ['OAUTHBEARER', 'XOAUTH2'];

    /** @return array<string, mixed> */
    public static function build(MailSettings $mail): array
    {
        $ssl = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'SNI_enabled' => true,
            'disable_compression' => true,
            'security_level' => 1,
        ];
        return [
            'IMAP' => [
                'host' => $mail->host,
                'port' => $mail->imapPort,
                'type' => self::SSL,
                'timeout' => 300,
                'lowerLogin' => true,
                'sasl' => self::SASL,
                'ssl' => $ssl,
                'use_expunge_all_on_delete' => false,
                'fast_simple_search' => true,
                'force_select' => false,
                'message_all_headers' => false,
                'message_list_limit' => 10000,
                'search_filter' => '',
                'spam_headers' => 'rspamd,spamassassin,bogofilter',
                'virus_headers' => 'rspamd,clamav',
                'disabled_capabilities' => [],
            ],
            'SMTP' => [
                'host' => $mail->host,
                'port' => $mail->smtpPort,
                'type' => self::STARTTLS,
                'timeout' => 60,
                'lowerLogin' => true,
                'sasl' => self::SASL,
                'ssl' => $ssl,
                'useAuth' => true,
            ],
            'Sieve' => [
                'host' => $mail->host,
                'port' => $mail->sievePort,
                'type' => self::STARTTLS,
                'timeout' => 10,
                'lowerLogin' => true,
                'sasl' => self::SASL,
                'ssl' => $ssl,
                'enabled' => true,
                'authLiteral' => true,
            ],
            'whiteList' => '',
        ];
    }
}
