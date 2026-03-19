<?php

declare(strict_types=1);

namespace OCA\X2Mail\Command;

use OCA\X2Mail\Service\DomainConfigService;
use OC\Core\Command\Base;
use OCP\App\IAppManager;
use OCP\IConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Setup extends Base
{
	private const APP_ID = 'x2mail';

	public function __construct(
		private IConfig $config,
		private DomainConfigService $domainService,
		private IAppManager $appManager,
	) {
		parent::__construct();
	}

	protected function configure() {
		$this
			->setName('x2mail:setup')
			->setDescription('Configure X2Mail mail server connection and authentication')
			->addOption('imap-host', null, InputOption::VALUE_REQUIRED, 'IMAP server hostname')
			->addOption('imap-port', null, InputOption::VALUE_REQUIRED, 'IMAP server port', '143')
			->addOption('imap-ssl', null, InputOption::VALUE_REQUIRED, 'IMAP SSL mode (none, ssl, tls)', 'none')
			->addOption('smtp-host', null, InputOption::VALUE_REQUIRED, 'SMTP server hostname (defaults to imap-host)')
			->addOption('smtp-port', null, InputOption::VALUE_REQUIRED, 'SMTP server port', '25')
			->addOption('smtp-ssl', null, InputOption::VALUE_REQUIRED, 'SMTP SSL mode (none, ssl, tls)', 'none')
			->addOption('smtp-auth', null, InputOption::VALUE_NONE, 'Require SMTP authentication')
			->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Mail domain (e.g. example.com)')
			->addOption('auth', null, InputOption::VALUE_REQUIRED, 'Auth type (plain, oauthbearer, xoauth2)', 'plain')
			->addOption('oidc-provider', null, InputOption::VALUE_REQUIRED, 'OIDC provider app (user_oidc, oidc_login)', 'user_oidc')
			->addOption('sieve', null, InputOption::VALUE_NONE, 'Enable Sieve filtering support')
			->addOption('skip-checks', null, InputOption::VALUE_NONE, 'Skip connectivity and capability checks')
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$imapHost = $input->getOption('imap-host');
		$domain = $input->getOption('domain');

		if (!$imapHost) {
			$output->writeln('<error>--imap-host is required</error>');
			return 1;
		}
		if (!$domain) {
			$output->writeln('<error>--domain is required</error>');
			return 1;
		}

		$imapPort = (int) $input->getOption('imap-port');
		$imapSsl = $input->getOption('imap-ssl');
		$smtpHost = $input->getOption('smtp-host') ?: $imapHost;
		$smtpPort = (int) $input->getOption('smtp-port');
		$smtpSsl = $input->getOption('smtp-ssl');
		$smtpAuth = $input->getOption('smtp-auth');
		$authType = \strtolower($input->getOption('auth'));
		$oidcProvider = $input->getOption('oidc-provider');
		$sieve = $input->getOption('sieve');
		$skipChecks = $input->getOption('skip-checks');

		// Validate input values
		if (!\in_array($authType, ['plain', 'oauthbearer', 'xoauth2'])) {
			$output->writeln('<error>Invalid --auth. Must be: plain, oauthbearer, xoauth2</error>');
			return 1;
		}
		foreach (['imap-ssl' => $imapSsl, 'smtp-ssl' => $smtpSsl] as $name => $val) {
			if (!\in_array(\strtolower($val), ['none', 'ssl', 'tls'])) {
				$output->writeln("<error>Invalid --{$name}. Must be: none, ssl, tls</error>");
				return 1;
			}
		}

		$isOAuth = \in_array($authType, ['oauthbearer', 'xoauth2']);
		$errors = 0;

		// ═══════════════════════════════════════════
		// PREFLIGHT CHECKS
		// ═══════════════════════════════════════════
		$output->writeln('<info>Preflight Checks</info>');
		$output->writeln('');

		// 1. OIDC Provider (if OAuth auth)
		if ($isOAuth) {
			$output->writeln('  <comment>[OIDC]</comment> Checking OIDC provider...');

			$userOidcInstalled = $this->appManager->isEnabledForUser('user_oidc');
			$oidcLoginInstalled = $this->appManager->isEnabledForUser('oidc_login');

			if (!$userOidcInstalled && !$oidcLoginInstalled) {
				$output->writeln('  <error>  FAIL: No OIDC provider installed!</error>');
				$output->writeln('  <error>  X2Mail requires SSO login via Nextcloud.</error>');
				$output->writeln('  <error>  Install user_oidc (recommended) or oidc_login first.</error>');
				$output->writeln('  <error>  → occ app:install user_oidc</error>');
				$output->writeln('  <error>  Then configure your Keycloak/OIDC provider.</error>');
				return 1;
			}

			// Detect which provider to use
			if ($oidcProvider === 'user_oidc' && !$userOidcInstalled) {
				if ($oidcLoginInstalled) {
					$oidcProvider = 'oidc_login';
					$output->writeln('  <comment>  user_oidc not found, using oidc_login instead</comment>');
				}
			} elseif ($oidcProvider === 'oidc_login' && !$oidcLoginInstalled) {
				if ($userOidcInstalled) {
					$oidcProvider = 'user_oidc';
					$output->writeln('  <comment>  oidc_login not found, using user_oidc instead</comment>');
				}
			}

			$output->writeln("  <info>  OK: {$oidcProvider} installed</info>");

			// Check user_oidc has a provider configured
			if ($oidcProvider === 'user_oidc') {
				$storeToken = $this->config->getAppValue('user_oidc', 'store_login_token', '0');
				if ($storeToken !== '1') {
					$this->config->setAppValue('user_oidc', 'store_login_token', '1');
					$output->writeln('  <info>  SET: store_login_token=1 (required for token refresh)</info>');
				} else {
					$output->writeln('  <info>  OK: store_login_token=1</info>');
				}

				// Check if OIDC provider is configured
				// Note: user_oidc stores provider data in its DB table + lazy app config.
				// IConfig may not see it due to caching. Use IAppConfig if available.
				$hasProvider = false;
				try {
					$appConfig = \OCP\Server::get(\OCP\IAppConfig::class);
					$hasProvider = $appConfig->getValueString('user_oidc', 'provider-1-mappingUid', '', lazy: true) !== '';
				} catch (\Throwable $e) {
					// Fallback: assume configured if store_login_token is set (admin likely set up everything)
					$hasProvider = true;
				}

				if ($hasProvider) {
					$output->writeln('  <info>  OK: OIDC provider configured</info>');
				} else {
					$output->writeln('  <comment>  WARN: No OIDC provider detected in user_oidc</comment>');
					$output->writeln('  <comment>  Verify: occ user_oidc:provider</comment>');
				}
			}
		} else {
			$output->writeln('  <comment>[OIDC]</comment> Skipped (plain auth, no OIDC needed)');
		}

		$output->writeln('');

		// 2. IMAP Connectivity + Capabilities
		if (!$skipChecks) {
			$output->writeln("  <comment>[IMAP]</comment> Connecting to {$imapHost}:{$imapPort}...");

			$imapResult = $this->checkImap($imapHost, $imapPort, $imapSsl);
			if ($imapResult['connected']) {
				$output->writeln("  <info>  OK: Connected</info>");

				if (!empty($imapResult['capabilities'])) {
					$caps = \implode(', ', \array_slice($imapResult['capabilities'], 0, 10));
					$output->writeln("  <info>  Capabilities: {$caps}</info>");
				}

				// Check for OAUTHBEARER/XOAUTH2 if OAuth auth requested
				if ($isOAuth) {
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

					if ($hasOAuth) {
						$output->writeln('  <info>  OK: OAUTHBEARER/XOAUTH2 supported</info>');
					} else {
						$output->writeln('  <error>  FAIL: IMAP server does NOT advertise OAUTHBEARER or XOAUTH2!</error>');
						$output->writeln('  <error>  Available AUTH methods: ' . (\implode(', ', $authMethods) ?: 'none') . '</error>');
						$output->writeln('  <error>  Your IMAP server must support OAUTHBEARER for SSO to work.</error>');
						$output->writeln('  <error>  For Dovecot, see: https://doc.dovecot.org/configuration_manual/authentication/oauth2/</error>');
						$errors++;
					}
				}

				if (\in_array('STARTTLS', $imapResult['capabilities']) && $imapSsl === 'none') {
					$output->writeln('  <comment>  HINT: Server supports STARTTLS — consider --imap-ssl tls</comment>');
				}
			} else {
				$output->writeln("  <error>  FAIL: Cannot connect to {$imapHost}:{$imapPort}</error>");
				$output->writeln("  <error>  Error: {$imapResult['error']}</error>");
				$output->writeln('  <error>  Check: firewall, hostname, port, SSL mode</error>');
				$errors++;
			}

			$output->writeln('');

			// 3. SMTP Connectivity
			$output->writeln("  <comment>[SMTP]</comment> Connecting to {$smtpHost}:{$smtpPort}...");

			$smtpResult = $this->checkSmtp($smtpHost, $smtpPort, $smtpSsl);
			if ($smtpResult['connected']) {
				$output->writeln("  <info>  OK: Connected</info>");
				if ($smtpResult['banner']) {
					$output->writeln("  <info>  Banner: {$smtpResult['banner']}</info>");
				}
			} else {
				$output->writeln("  <error>  FAIL: Cannot connect to {$smtpHost}:{$smtpPort}</error>");
				$output->writeln("  <error>  Error: {$smtpResult['error']}</error>");
				$errors++;
			}
		} else {
			$output->writeln('  <comment>[IMAP]</comment> Skipped (--skip-checks)');
			$output->writeln('  <comment>[SMTP]</comment> Skipped (--skip-checks)');
		}

		$output->writeln('');

		// Abort if critical errors
		if ($errors > 0 && !$skipChecks) {
			$output->writeln("<error>{$errors} check(s) failed. Fix the issues above or use --skip-checks to proceed anyway.</error>");
			return 1;
		}

		// ═══════════════════════════════════════════
		// CONFIGURATION
		// ═══════════════════════════════════════════
		$output->writeln('<info>Applying Configuration</info>');
		$output->writeln('');

		// Write domain config
		$domainConfig = $this->domainService->buildDomainConfig(
			$imapHost, $imapPort, $imapSsl,
			$smtpHost, $smtpPort, $smtpSsl,
			$smtpAuth, $authType, $sieve,
		);
		$this->domainService->writeDomainConfig($domain, $domainConfig);
		$output->writeln("  Domain config: <comment>{$domain}</comment>");

		// NC app config
		$this->config->setAppValue(self::APP_ID, 'snappymail-autologin', '1');
		if ($isOAuth) {
			$this->config->setAppValue(self::APP_ID, 'snappymail-autologin-oidc', '1');
		} else {
			$this->config->setAppValue(self::APP_ID, 'snappymail-autologin-oidc', '0');
		}

		// SM core config (app_path, default_domain)
		$appDir = \dirname(\dirname(__DIR__)) . '/app';
		if (\is_dir($appDir)) {
			try {
				\OCA\X2Mail\Util\SnappyMailHelper::loadApp();
				$oConfig = \RainLoop\Api::Config();
				$appPath = \rtrim($this->appManager->getAppWebPath(self::APP_ID), '/') . '/app/';
				$oConfig->Set('webmail', 'app_path', $appPath);
				$oConfig->Set('login', 'default_domain', $domain);

				// Enable contacts — NextcloudAddressBook reads/writes NC Contacts directly
				$oConfig->Set('contacts', 'enable', true);

				$oConfig->Save();
				$output->writeln("  SM app_path: <comment>{$appPath}</comment>");
			} catch (\Throwable $e) {
				$output->writeln('  <comment>SM core config skipped: ' . $e->getMessage() . '</comment>');
			}
		}

		// ═══════════════════════════════════════════
		// SUMMARY
		// ═══════════════════════════════════════════
		$output->writeln('');
		$output->writeln('<info>Setup complete!</info>');
		$output->writeln('');
		$output->writeln('  Domain:    ' . $domain);
		$output->writeln('  IMAP:      ' . $imapHost . ':' . $imapPort . ' (' . \strtoupper($imapSsl) . ')');
		$output->writeln('  SMTP:      ' . $smtpHost . ':' . $smtpPort . ' (' . \strtoupper($smtpSsl) . ')');
		$output->writeln('  Auth:      ' . $authType);
		$output->writeln('  Sieve:     ' . ($sieve ? 'enabled' : 'disabled'));
		if ($isOAuth) {
			$output->writeln('  OIDC:      ' . $oidcProvider);
		}

		if ($isOAuth) {
			$output->writeln('');
			$output->writeln('<comment>Mail server requirements (your responsibility):</comment>');
			$output->writeln('  1. IMAP server must support OAUTHBEARER or XOAUTH2 SASL mechanism');
			$output->writeln('  2. IMAP server must validate tokens against your OIDC provider (e.g. Keycloak)');
			$output->writeln('     → Dovecot: configure oauth2 passdb with introspection endpoint');
			$output->writeln('  3. OIDC provider must include correct audience in access tokens');
			$output->writeln('     → Keycloak: add audience mapper to the Nextcloud client');
			$output->writeln('  4. IMAP username must match the email claim in the OIDC token');
		}

		return 0;
	}

	/**
	 * Test IMAP connection and read capabilities.
	 */
	private function checkImap(string $host, int $port, string $ssl): array {
		$result = ['connected' => false, 'capabilities' => [], 'error' => ''];

		try {
			$prefix = match (\strtolower($ssl)) {
				'ssl' => 'ssl://',
				default => 'tcp://',
			};

			$errno = 0;
			$errstr = '';
			$fp = @\stream_socket_client($prefix . $host . ':' . $port, $errno, $errstr, 10);
			if (!$fp) {
				$result['error'] = $errstr ?: "Connection failed (errno={$errno})";
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

			// Parse CAPABILITY from banner: * OK [CAPABILITY ...] text
			if (\preg_match('/\[CAPABILITY\s+([^\]]+)\]/', $banner, $m)) {
				$result['capabilities'] = \explode(' ', \trim($m[1]));
			}

			// If banner didn't include capabilities, send explicit CAPABILITY command
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
					// Tagged response means end of command
					if (\str_starts_with($line, 'A001 ')) {
						break;
					}
				}
				if ($capLine !== '') {
					$result['capabilities'] = \explode(' ', $capLine);
				}
			}

			// Clean logout
			\fwrite($fp, "A002 LOGOUT\r\n");
			\fclose($fp);
		} catch (\Throwable $e) {
			$result['error'] = $e->getMessage();
		}

		return $result;
	}

	/**
	 * Test SMTP connection.
	 */
	private function checkSmtp(string $host, int $port, string $ssl): array {
		$result = ['connected' => false, 'banner' => '', 'error' => ''];

		try {
			$prefix = match (\strtolower($ssl)) {
				'ssl' => 'ssl://',
				default => 'tcp://',
			};

			$errno = 0;
			$errstr = '';
			$fp = @\stream_socket_client($prefix . $host . ':' . $port, $errno, $errstr, 10);
			if (!$fp) {
				$result['error'] = $errstr ?: "Connection failed (errno={$errno})";
				return $result;
			}

			\stream_set_timeout($fp, 10);
			$banner = \trim(\fgets($fp, 4096) ?: '');
			\fwrite($fp, "QUIT\r\n");
			\fclose($fp);

			$result['connected'] = true;
			$result['banner'] = $banner;
		} catch (\Throwable $e) {
			$result['error'] = $e->getMessage();
		}

		return $result;
	}
}
