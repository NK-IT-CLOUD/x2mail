<?php

declare(strict_types=1);

namespace OCA\X2Mail\Command;

use OCA\X2Mail\Service\DomainConfigService;
use OC\Core\Command\Base;
use OCP\IConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Status extends Base
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
			->setName('x2mail:status')
			->setDescription('Show X2Mail configuration status')
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$output->writeln('<info>X2Mail Status</info>');
		$output->writeln('');

		// Domains
		$domains = $this->domainService->listDomains();
		$output->writeln('<comment>Configured Domains:</comment>');
		if (empty($domains)) {
			$output->writeln('  (none)');
		} else {
			foreach ($domains as $domain) {
				$config = $this->domainService->readDomainConfig($domain);
				if ($config) {
					$imapHost = $config['IMAP']['host'] ?? '?';
					$imapPort = $config['IMAP']['port'] ?? '?';
					$sslVal = $config['IMAP']['ssl'] ?? 0;
					$imapSsl = \is_int($sslVal) ? DomainConfigService::sslToString($sslVal) : 'custom';
					$smtpHost = $config['SMTP']['host'] ?? '?';
					$smtpPort = $config['SMTP']['port'] ?? '?';
					$sasl = $config['IMAP']['sasl'] ?? [];
					$authMode = 'password';
					if (\in_array('OAUTHBEARER', $sasl) || \in_array('XOAUTH2', $sasl)) {
						$authMode = 'OAUTHBEARER/XOAUTH2';
					}
					$sieve = ($config['Sieve']['enabled'] ?? false) ? 'yes' : 'no';
					$output->writeln("  {$domain}");
					$output->writeln("    IMAP: {$imapHost}:{$imapPort} ({$imapSsl})");
					$output->writeln("    SMTP: {$smtpHost}:{$smtpPort}");
					$output->writeln("    Auth: {$authMode}");
					$output->writeln("    Sieve: {$sieve}");
				} else {
					$output->writeln("  {$domain} (config unreadable)");
				}
			}
		}

		$output->writeln('');

		// OIDC status
		$output->writeln('<comment>OIDC Configuration:</comment>');
		$oidcEnabled = $this->config->getAppValue(self::APP_ID, 'snappymail-autologin-oidc', '0');
		$output->writeln('  OIDC auto-login: ' . ($oidcEnabled === '1' ? 'enabled' : 'disabled'));

		$appManager = \OC::$server->getAppManager();
		$providers = ['user_oidc', 'oidc_login'];
		foreach ($providers as $provider) {
			$installed = $appManager->isEnabledForUser($provider);
			$output->writeln("  {$provider}: " . ($installed ? 'installed' : 'not installed'));
			if ($installed && $provider === 'user_oidc') {
				$storeToken = $this->config->getAppValue('user_oidc', 'store_login_token', '0');
				$output->writeln("    store_login_token: {$storeToken}");
			}
		}

		$output->writeln('');

		// Auto-login status
		$output->writeln('<comment>Auto-login:</comment>');
		$autologin = $this->config->getAppValue(self::APP_ID, 'snappymail-autologin', '0');
		$autologinEmail = $this->config->getAppValue(self::APP_ID, 'snappymail-autologin-with-email', '0');
		$output->writeln('  By username: ' . ($autologin ? 'enabled' : 'disabled'));
		$output->writeln('  By email: ' . ($autologinEmail ? 'enabled' : 'disabled'));

		$output->writeln('');

		// SM version and app_path
		$output->writeln('<comment>SnappyMail Engine:</comment>');
		$appDir = \dirname(\dirname(__DIR__)) . '/app';
		if (\is_dir($appDir)) {
			try {
				\OCA\X2Mail\Util\SnappyMailHelper::loadApp();
				$output->writeln('  Version: ' . APP_VERSION);
				$output->writeln('  app_path: ' . \RainLoop\Api::Config()->Get('webmail', 'app_path', '(not set)'));
			} catch (\Throwable $e) {
				$output->writeln('  <error>Failed to load SM core: ' . $e->getMessage() . '</error>');
			}
		} else {
			$output->writeln('  <comment>SM core not present at app/</comment>');
		}

		$output->writeln('');
		$output->writeln('  Data path: ' . $this->domainService->getDataPath());

		return 0;
	}
}
