<?php

declare(strict_types=1);

namespace OCA\X2Mail\Controller;

use OCA\X2Mail\Service\DomainConfigService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;

class SetupController extends Controller
{
	private const APP_ID = 'x2mail';

	public function __construct(
		string $appName,
		IRequest $request,
		private IAppConfig $appConfig,
		private IAppManager $appManager,
		private DomainConfigService $domainService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Load current setup configuration for the wizard form.
	 */
	#[NoCSRFRequired]
	public function getConfig(): JSONResponse
	{
		$domains = $this->domainService->listDomains();
		$domainConfigs = [];

		foreach ($domains as $domain) {
			$raw = $this->domainService->readDomainConfig($domain);
			if (!$raw) {
				continue;
			}

			$imapSasl = $raw['IMAP']['sasl'] ?? [];
			$authType = 'plain';
			if (\in_array('OAUTHBEARER', $imapSasl)) {
				$authType = \array_search('OAUTHBEARER', $imapSasl) === 0 ? 'oauthbearer' : 'xoauth2';
			} elseif (\in_array('XOAUTH2', $imapSasl)) {
				$authType = 'xoauth2';
			}

			$domainConfigs[$domain] = [
				'imap_host' => $raw['IMAP']['host'] ?? '',
				'imap_port' => $raw['IMAP']['port'] ?? 143,
				'imap_ssl' => DomainConfigService::sslToString($raw['IMAP']['type'] ?? 0),
				'smtp_host' => $raw['SMTP']['host'] ?? '',
				'smtp_port' => $raw['SMTP']['port'] ?? 25,
				'smtp_ssl' => DomainConfigService::sslToString($raw['SMTP']['type'] ?? 0),
				'smtp_auth' => $raw['SMTP']['useAuth'] ?? false,
				'auth_type' => $authType,
				'sieve' => $raw['Sieve']['enabled'] ?? false,
			];
		}

		// OIDC status
		$userOidcInstalled = $this->appManager->isEnabledForUser('user_oidc');
		$oidcLoginInstalled = $this->appManager->isEnabledForUser('oidc_login');
		$oidcAutoLogin = $this->appConfig->getValueString(self::APP_ID, 'snappymail-autologin-oidc', '0');

		$oidcProvider = 'none';
		if ($userOidcInstalled) {
			$oidcProvider = 'user_oidc';
		} elseif ($oidcLoginInstalled) {
			$oidcProvider = 'oidc_login';
		}

		return new JSONResponse([
			'domains' => $domainConfigs,
			'oidc' => [
				'enabled' => $oidcAutoLogin === '1',
				'provider' => $oidcProvider,
				'user_oidc' => $userOidcInstalled,
				'oidc_login' => $oidcLoginInstalled,
			],
		]);
	}

	/**
	 * Run preflight checks against IMAP/SMTP/OIDC.
	 */
	public function preflightCheck(): JSONResponse
	{
		$imapHost = $this->request->getParam('imap_host', '');
		$imapPort = (int) $this->request->getParam('imap_port', 143);
		$imapSsl = \strtolower($this->request->getParam('imap_ssl', 'none'));
		$smtpHost = $this->request->getParam('smtp_host', '') ?: $imapHost;
		$smtpPort = (int) $this->request->getParam('smtp_port', 25);
		$smtpSsl = \strtolower($this->request->getParam('smtp_ssl', 'none'));
		$authType = \strtolower($this->request->getParam('auth_type', 'plain'));

		if ($imapPort < 1 || $imapPort > 65535) {
			return new JSONResponse(['error' => 'Invalid IMAP port'], 400);
		}
		if ($smtpPort < 1 || $smtpPort > 65535) {
			return new JSONResponse(['error' => 'Invalid SMTP port'], 400);
		}

		// Validate hostname format — prevent injection of special characters
		if ($imapHost !== '' && !\preg_match('/\A[a-zA-Z0-9.\-]+\z/', $imapHost)) {
			return new JSONResponse(['error' => 'Invalid IMAP hostname'], 400);
		}
		if ($smtpHost !== '' && !\preg_match('/\A[a-zA-Z0-9.\-]+\z/', $smtpHost)) {
			return new JSONResponse(['error' => 'Invalid SMTP hostname'], 400);
		}

		$results = [];

		// IMAP check
		$imapResult = $this->checkImap($imapHost, $imapPort, $imapSsl);
		$results['imap'] = $imapResult;

		// OAuth capability check
		if ($imapResult['connected'] && \in_array($authType, ['oauthbearer', 'xoauth2'])) {
			$hasOAuth = false;
			$authMethods = [];
			foreach ($imapResult['capabilities'] as $cap) {
				if (\str_starts_with($cap, 'AUTH=')) {
					$method = \substr($cap, 5);
					$authMethods[] = $method;
					if ($method === 'OAUTHBEARER' || $method === 'XOAUTH2') {
						$hasOAuth = true;
					}
				}
			}
			$results['imap']['oauth_supported'] = $hasOAuth;
			$results['imap']['auth_methods'] = $authMethods;
		}

		// SMTP check
		$results['smtp'] = $this->checkSmtp($smtpHost, $smtpPort, $smtpSsl);

		// OIDC check
		if (\in_array($authType, ['oauthbearer', 'xoauth2'])) {
			$userOidc = $this->appManager->isEnabledForUser('user_oidc');
			$oidcLogin = $this->appManager->isEnabledForUser('oidc_login');

			$oidcResult = [
				'user_oidc' => $userOidc,
				'oidc_login' => $oidcLogin,
				'any_installed' => $userOidc || $oidcLogin,
			];

			if ($userOidc) {
				$storeToken = $this->appConfig->getValueString('user_oidc', 'store_login_token', '0');
				$oidcResult['store_login_token'] = $storeToken === '1';
			}

			$results['oidc'] = $oidcResult;
		}

		return new JSONResponse($results);
	}

	/**
	 * Save setup configuration (create or update domain).
	 */
	public function saveSetup(): JSONResponse
	{
		$domain = \trim($this->request->getParam('domain', ''));
		$imapHost = \trim($this->request->getParam('imap_host', ''));
		$imapPort = (int) $this->request->getParam('imap_port', 143);
		$imapSsl = \strtolower($this->request->getParam('imap_ssl', 'none'));
		$smtpHost = \trim($this->request->getParam('smtp_host', '')) ?: $imapHost;
		$smtpPort = (int) $this->request->getParam('smtp_port', 25);
		$smtpSsl = \strtolower($this->request->getParam('smtp_ssl', 'none'));
		$smtpAuth = (bool) $this->request->getParam('smtp_auth', false);
		$authType = \strtolower($this->request->getParam('auth_type', 'plain'));
		$sieve = (bool) $this->request->getParam('sieve', false);

		// Validation
		if ($domain === '') {
			return new JSONResponse(['status' => 'error', 'message' => 'Domain is required'], 400);
		}
		if (!\preg_match('/\A[a-zA-Z0-9.\-]+\z/', $domain)) {
			return new JSONResponse(['status' => 'error', 'message' => 'Invalid domain name'], 400);
		}
		if ($imapHost === '') {
			return new JSONResponse(['status' => 'error', 'message' => 'IMAP host is required'], 400);
		}
		if ($imapPort < 1 || $imapPort > 65535) {
			return new JSONResponse(['status' => 'error', 'message' => 'Invalid IMAP port'], 400);
		}
		if ($smtpPort < 1 || $smtpPort > 65535) {
			return new JSONResponse(['status' => 'error', 'message' => 'Invalid SMTP port'], 400);
		}
		if (!\in_array($authType, ['plain', 'oauthbearer', 'xoauth2'])) {
			return new JSONResponse(['status' => 'error', 'message' => 'Invalid auth type'], 400);
		}
		if (!\in_array($imapSsl, ['none', 'ssl', 'starttls'])) {
			return new JSONResponse(['status' => 'error', 'message' => 'Invalid IMAP SSL mode'], 400);
		}
		if (!\in_array($smtpSsl, ['none', 'ssl', 'starttls'])) {
			return new JSONResponse(['status' => 'error', 'message' => 'Invalid SMTP SSL mode'], 400);
		}

		try {
			$domainConfig = $this->domainService->buildDomainConfig(
				$imapHost, $imapPort, $imapSsl,
				$smtpHost, $smtpPort, $smtpSsl,
				$smtpAuth, $authType, $sieve,
			);
			$this->domainService->writeDomainConfig($domain, $domainConfig);

			// Set app config for OIDC auto-login
			$isOAuth = \in_array($authType, ['oauthbearer', 'xoauth2']);
			$this->appConfig->setValueString(self::APP_ID, 'snappymail-autologin', '1');
			$this->appConfig->setValueString(self::APP_ID, 'snappymail-autologin-oidc', $isOAuth ? '1' : '0');

			// Ensure store_login_token is set for user_oidc
			if ($isOAuth && $this->appManager->isEnabledForUser('user_oidc')) {
				$this->appConfig->setValueString('user_oidc', 'store_login_token', '1');
			}

			// Set SM config for this auth mode
			try {
				\OCA\X2Mail\Util\SnappyMailHelper::loadApp();
				$oConfig = \RainLoop\Api::Config();
				$oConfig->Set('login', 'default_domain', $domain);
				if ($isOAuth) {
					$oConfig->Set('webmail', 'allow_additional_accounts', false);
					$oConfig->Set('login', 'sign_me_auto', \RainLoop\Enumerations\SignMeType::Unused);
					$oConfig->Set('imap', 'show_login_alert', false);
					$oConfig->Set('defaults', 'autologout', 15);
				}
				$oConfig->Save();
			} catch (\Throwable $e) {
				// Non-fatal
			}

			return new JSONResponse([
				'status' => 'success',
				'message' => "Domain '{$domain}' saved",
			]);
		} catch (\Throwable $e) {
			return new JSONResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
		}
	}

	/**
	 * Delete a domain configuration.
	 */
	public function deleteDomain(): JSONResponse
	{
		$domain = \trim($this->request->getParam('domain', ''));
		if ($domain === '') {
			return new JSONResponse(['status' => 'error', 'message' => 'Domain is required'], 400);
		}
		if (!\preg_match('/\A[a-zA-Z0-9.\-]+\z/', $domain)) {
			return new JSONResponse(['status' => 'error', 'message' => 'Invalid domain name'], 400);
		}

		try {
			$this->domainService->deleteDomainConfig($domain);
			return new JSONResponse(['status' => 'success', 'message' => "Domain '{$domain}' deleted"]);
		} catch (\Throwable $e) {
			return new JSONResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
		}
	}

	/** @return array<string, mixed> */
	private function checkImap(string $host, int $port, string $ssl): array
	{
		$result = ['connected' => false, 'capabilities' => [], 'error' => ''];

		if ($host === '') {
			$result['error'] = 'No host specified';
			return $result;
		}

		try {
			$prefix = match ($ssl) {
				'ssl' => 'ssl://',
				default => 'tcp://',
			};

			$errno = 0;
			$errstr = '';
			$fp = @\stream_socket_client($prefix . $host . ':' . $port, $errno, $errstr, 10);
			if (!$fp) {
				$result['error'] = 'Connection failed';
				return $result;
			}

			\stream_set_timeout($fp, 10);
			$banner = \fgets($fp, 4096);

			if (!$banner) {
				\fclose($fp);
				$result['error'] = 'No response from server';
				return $result;
			}

			$result['connected'] = true;

			if (\preg_match('/\[CAPABILITY\s+([^\]]+)\]/', $banner, $m)) {
				$result['capabilities'] = \explode(' ', \trim($m[1]));
			}

			if (empty($result['capabilities'])) {
				\fwrite($fp, "A001 CAPABILITY\r\n");
				$capLine = '';
				$tries = 0;
				while ($tries++ < 10) {
					$line = \fgets($fp, 4096);
					if ($line === false) {
						break;
					}
					if (\str_starts_with($line, '* CAPABILITY ')) {
						$capLine = \trim(\substr($line, 13));
					}
					if (\str_starts_with($line, 'A001 ')) {
						break;
					}
				}
				if ($capLine !== '') {
					$result['capabilities'] = \explode(' ', $capLine);
				}
			}

			\fwrite($fp, "A002 LOGOUT\r\n");
			\fclose($fp);
		} catch (\Throwable $e) {
			$result['error'] = 'Connection failed';
		}

		return $result;
	}

	/** @return array<string, mixed> */
	private function checkSmtp(string $host, int $port, string $ssl): array
	{
		$result = ['connected' => false, 'banner' => '', 'error' => ''];

		if ($host === '') {
			$result['error'] = 'No host specified';
			return $result;
		}

		try {
			$prefix = match ($ssl) {
				'ssl' => 'ssl://',
				default => 'tcp://',
			};

			$errno = 0;
			$errstr = '';
			$fp = @\stream_socket_client($prefix . $host . ':' . $port, $errno, $errstr, 10);
			if (!$fp) {
				$result['error'] = 'Connection failed';
				return $result;
			}

			\stream_set_timeout($fp, 10);
			$banner = \trim(\fgets($fp, 4096) ?: '');
			\fwrite($fp, "QUIT\r\n");
			\fclose($fp);

			$result['connected'] = true;
			$result['banner'] = $banner;
		} catch (\Throwable $e) {
			$result['error'] = 'Connection failed';
		}

		return $result;
	}
}
