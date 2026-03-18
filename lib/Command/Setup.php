<?php

declare(strict_types=1);

namespace OCA\X2Mail\Command;

use OCA\X2Mail\Service\DomainConfigService;
use OC\Core\Command\Base;
use OCP\IConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Setup extends Base
{
	private const APP_ID = 'x2mail';

	protected IConfig $config;
	protected DomainConfigService $domainService;

	public function __construct(IConfig $config, DomainConfigService $domainService) {
		parent::__construct();
		$this->config = $config;
		$this->domainService = $domainService;
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
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		// Validate required options
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

		// Validate auth type
		$validAuth = ['plain', 'oauthbearer', 'xoauth2'];
		if (!\in_array($authType, $validAuth)) {
			$output->writeln('<error>Invalid --auth value. Must be one of: ' . \implode(', ', $validAuth) . '</error>');
			return 1;
		}

		// Validate SSL values
		$validSsl = ['none', 'ssl', 'tls'];
		if (!\in_array(\strtolower($imapSsl), $validSsl)) {
			$output->writeln('<error>Invalid --imap-ssl value. Must be one of: ' . \implode(', ', $validSsl) . '</error>');
			return 1;
		}
		if (!\in_array(\strtolower($smtpSsl), $validSsl)) {
			$output->writeln('<error>Invalid --smtp-ssl value. Must be one of: ' . \implode(', ', $validSsl) . '</error>');
			return 1;
		}

		$output->writeln('<info>Configuring X2Mail...</info>');

		// 1. Build and write domain config
		$domainConfig = $this->domainService->buildDomainConfig(
			$imapHost, $imapPort, $imapSsl,
			$smtpHost, $smtpPort, $smtpSsl,
			$smtpAuth, $authType, $sieve,
		);

		$this->domainService->writeDomainConfig($domain, $domainConfig);
		$output->writeln("  Domain config written: <comment>{$domain}</comment>");

		// 2. Set NC app config values
		$isOAuth = \in_array($authType, ['oauthbearer', 'xoauth2']);

		$this->config->setAppValue(self::APP_ID, 'snappymail-autologin', '1');
		$output->writeln('  Auto-login: <comment>enabled</comment>');

		if ($isOAuth) {
			$this->config->setAppValue(self::APP_ID, 'snappymail-autologin-oidc', '1');
			$output->writeln('  OIDC auto-login: <comment>enabled</comment>');

			// 3. Check OIDC provider
			$appManager = \OC::$server->getAppManager();
			if ($appManager->isEnabledForUser($oidcProvider)) {
				$output->writeln("  OIDC provider: <comment>{$oidcProvider}</comment> (installed)");

				// Check store_login_token for user_oidc
				if ($oidcProvider === 'user_oidc') {
					$storeToken = $this->config->getAppValue('user_oidc', 'store_login_token', '0');
					if ($storeToken !== '1') {
						$this->config->setAppValue('user_oidc', 'store_login_token', '1');
						$output->writeln('  <comment>Set user_oidc store_login_token=1</comment>');
					} else {
						$output->writeln('  user_oidc store_login_token: <comment>already set</comment>');
					}
				}
			} else {
				$output->writeln("  <error>WARNING: OIDC provider '{$oidcProvider}' is not installed/enabled!</error>");
				$output->writeln("  <error>OAUTHBEARER/XOAUTH2 auth will not work without an OIDC provider.</error>");
			}
		} else {
			$this->config->setAppValue(self::APP_ID, 'snappymail-autologin-oidc', '0');
		}

		// 4. Set app_path in SM application.ini (if SM core is present)
		$appDir = \dirname(\dirname(__DIR__)) . '/app';
		if (\is_dir($appDir)) {
			try {
				\OCA\X2Mail\Util\SnappyMailHelper::loadApp();
				$oConfig = \RainLoop\Api::Config();
				$appPath = \OC::$server->getAppManager()->getAppWebPath(self::APP_ID) . '/app/';
				$oConfig->Set('webmail', 'app_path', $appPath);
				$oConfig->Set('login', 'default_domain', $domain);
				$oConfig->Save();
				$output->writeln("  app_path: <comment>{$appPath}</comment>");
				$output->writeln("  default_domain: <comment>{$domain}</comment>");
			} catch (\Throwable $e) {
				$output->writeln('  <comment>SM core not loaded, skipping app_path config</comment>');
			}
		} else {
			$output->writeln('  <comment>SM core not present at app/, skipping app_path config</comment>');
		}

		// Summary
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

		return 0;
	}
}
