<?php

declare(strict_types=1);

namespace OCA\X2Mail\Service;

/**
 * Service to programmatically read/write SM domain config files.
 *
 * Domain configs are stored as JSON in:
 *   {datadir}/appdata_x2mail/_data_/_default_/domains/{domain}.json
 */
class DomainConfigService
{
	private const SSL_NONE = 0;
	private const SSL_SSL = 1;
	private const SSL_TLS = 2;

	/**
	 * Map string SSL type to SM numeric value.
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
	 * Map SM numeric SSL value to human-readable string.
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
		return \rtrim(\trim(\OC::$server->getSystemConfig()->getValue('datadirectory', '')), '\\/') . '/appdata_x2mail';
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
	 */
	public function writeDomainConfig(string $domain, array $config): void
	{
		$domainsPath = $this->getDomainsPath();
		if (!\is_dir($domainsPath)) {
			\mkdir($domainsPath, 0755, true);
		}

		$file = $domainsPath . '/' . $domain . '.json';
		$json = \json_encode($config, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
		\file_put_contents($file, $json);
	}

	/**
	 * Read a domain config JSON file.
	 */
	public function readDomainConfig(string $domain): ?array
	{
		$file = $this->getDomainsPath() . '/' . $domain . '.json';
		if (!\file_exists($file)) {
			return null;
		}

		$content = \file_get_contents($file);
		$data = \json_decode($content, true);
		return \is_array($data) ? $data : null;
	}

	/**
	 * List configured domains.
	 */
	public function listDomains(): array
	{
		$domainsPath = $this->getDomainsPath();
		if (!\is_dir($domainsPath)) {
			return [];
		}

		$domains = [];
		foreach (\glob($domainsPath . '/*.json') as $file) {
			$name = \basename($file, '.json');
			if ($name !== 'disabled') {
				$domains[] = $name;
			}
		}
		return $domains;
	}

	/**
	 * Build a domain config array from setup parameters.
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
		$imapSslInt = self::sslToInt($imapSsl);
		$smtpSslInt = self::sslToInt($smtpSsl);

		// Determine SASL methods based on auth type
		$imapSasl = match ($authType) {
			'oauthbearer' => ['OAUTHBEARER', 'XOAUTH2', 'PLAIN', 'LOGIN'],
			'xoauth2' => ['XOAUTH2', 'OAUTHBEARER', 'PLAIN', 'LOGIN'],
			default => ['PLAIN', 'LOGIN'],
		};

		$smtpSasl = ['PLAIN', 'LOGIN'];

		$config = [
			'IMAP' => [
				'host' => $imapHost,
				'port' => $imapPort,
				'ssl' => $imapSslInt,
				'shortLogin' => true,
				'sasl' => $imapSasl,
			],
			'SMTP' => [
				'host' => $smtpHost,
				'port' => $smtpPort,
				'ssl' => $smtpSslInt,
				'shortLogin' => true,
				'useAuth' => $smtpAuth,
				'sasl' => $smtpSasl,
			],
			'Sieve' => [
				'enabled' => $sieve,
				'host' => $imapHost,
				'port' => 4190,
			],
		];

		return $config;
	}
}
