<?php

declare(strict_types=1);

namespace OCA\X2Mail\Service;

use OCP\IConfig;

/**
 * Service to programmatically read/write engine domain config files.
 *
 * Domain configs are stored as JSON in:
 *   {datadir}/appdata_x2mail/_data_/_default_/domains/{domain}.json
 */
class DomainConfigService
{
    public function __construct(
        private IConfig $config,
    ) {
    }

    /**
     * Validate domain name to prevent path traversal.
     *
     * @throws \InvalidArgumentException if domain contains invalid characters
     */
    private function validateDomain(string $domain): void
    {
        if ($domain === '' || $domain === '.' || $domain === '..' || !\preg_match('/\A[a-zA-Z0-9.\-]+\z/', $domain)) {
            throw new \InvalidArgumentException("Invalid domain name: {$domain}");
        }
    }

    private const SSL_NONE = 0;
    private const SSL_SSL = 1;
    private const SSL_TLS = 2;

    /**
     * Map string SSL type to engine numeric value.
     */
    public static function sslToInt(string $ssl): int
    {
        return match (\strtolower($ssl)) {
            'ssl' => self::SSL_SSL,
            'tls', 'starttls' => self::SSL_TLS,
            default => self::SSL_NONE,
        };
    }

    /**
     * Map engine numeric SSL value to human-readable string.
     */
    public static function sslToString(int $ssl): string
    {
        return match ($ssl) {
            self::SSL_SSL => 'SSL',
            self::SSL_TLS => 'STARTTLS',
            default => 'None',
        };
    }

    /**
     * Get the appdata_x2mail path.
     */
    public function getDataPath(): string
    {
        return \rtrim(\trim($this->config->getSystemValue('datadirectory', '')), '\\/') . '/appdata_x2mail';
    }

    /**
     * Get path to domains directory.
     */
    private function getDomainsPath(): string
    {
        return $this->getDataPath() . '/_data_/_default_/domains';
    }

    /**
     * Write a domain config JSON file.
     *
     * @param array<string, mixed> $config
     */
    public function writeDomainConfig(string $domain, array $config): void
    {
        $this->validateDomain($domain);
        $domainsPath = $this->getDomainsPath();
        if (!\is_dir($domainsPath)) {
            \mkdir($domainsPath, 0755, true);
        }

        $file = $domainsPath . '/' . $domain . '.json';
        $json = \json_encode($config, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode domain config as JSON: ' . \json_last_error_msg());
        }
        if (\file_put_contents($file, $json) === false) {
            throw new \RuntimeException('Failed to write domain config to ' . $file);
        }
    }

    /**
     * Read a domain config JSON file.
     *
     * @return array<string, mixed>|null
     */
    public function readDomainConfig(string $domain): ?array
    {
        $this->validateDomain($domain);
        $file = $this->getDomainsPath() . '/' . $domain . '.json';
        if (!\file_exists($file)) {
            return null;
        }

        $content = \file_get_contents($file);
        if ($content === false) {
            return null;
        }
        $data = \json_decode($content, true);
        return \is_array($data) ? $data : null;
    }

    /**
     * Delete a domain config file.
     */
    public function deleteDomainConfig(string $domain): void
    {
        $this->validateDomain($domain);
        $file = $this->getDomainsPath() . '/' . $domain . '.json';
        if (!\file_exists($file)) {
            throw new \RuntimeException("Domain config not found: {$domain}");
        }
        if (!\unlink($file)) {
            throw new \RuntimeException("Failed to delete domain config: {$domain}");
        }
    }

    /**
     * List configured domains.
     *
     * @return list<string>
     */
    public function listDomains(): array
    {
        $domainsPath = $this->getDomainsPath();
        if (!\is_dir($domainsPath)) {
            return [];
        }

        $domains = [];
        foreach (\glob($domainsPath . '/*.json') ?: [] as $file) {
            $name = \basename($file, '.json');
            if ($name !== 'disabled') {
                $domains[] = $name;
            }
        }
        return $domains;
    }

    /**
     * Engine SSL config object template.
     *
     * @return array<string, bool|int>
     */
    private static function sslConfig(): array
    {
        return [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'SNI_enabled' => true,
            'disable_compression' => true,
            'security_level' => 1,
        ];
    }

    /**
     * Build a complete engine domain config from setup parameters.
     * Uses the full engine format with all required keys.
     *
     * @return array<string, mixed>
     */
    public function buildDomainConfig(
        string $imapHost,
        int $imapPort,
        string $imapSsl,
        string $smtpHost,
        int $smtpPort,
        string $smtpSsl,
        bool $smtpAuth,
        string $authType,
        bool $sieve,
    ): array {
        $imapType = self::sslToInt($imapSsl);
        $smtpType = self::sslToInt($smtpSsl);

        $oauthSasl = ['OAUTHBEARER', 'XOAUTH2', 'PLAIN', 'LOGIN'];
        $plainSasl = ['PLAIN', 'LOGIN'];

        $imapSasl = ($authType === 'oauth') ? $oauthSasl : $plainSasl;
        $smtpSasl = ($authType === 'oauth') ? $oauthSasl : $plainSasl;

        return [
            'IMAP' => [
                'host' => $imapHost,
                'port' => $imapPort,
                'type' => $imapType,
                'timeout' => 300,
                'shortLogin' => false,
                'lowerLogin' => true,
                'stripLogin' => '',
                'sasl' => $imapSasl,
                'ssl' => self::sslConfig(),
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
                'host' => $smtpHost,
                'port' => $smtpPort,
                'type' => $smtpType,
                'timeout' => 60,
                'shortLogin' => false,
                'lowerLogin' => true,
                'stripLogin' => '',
                'sasl' => $smtpSasl,
                'ssl' => self::sslConfig(),
                'useAuth' => $smtpAuth,
                'setSender' => false,
                'usePhpMail' => false,
                'authPlainLine' => false,
            ],
            'Sieve' => [
                'host' => $imapHost,
                'port' => 4190,
                'type' => 0,
                'timeout' => 10,
                'shortLogin' => false,
                'lowerLogin' => true,
                'stripLogin' => '',
                'sasl' => ['PLAIN', 'LOGIN'],
                'ssl' => self::sslConfig(),
                'enabled' => $sieve,
                'authLiteral' => true,
            ],
            'whiteList' => '',
        ];
    }
}
