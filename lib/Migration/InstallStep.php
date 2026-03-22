<?php

namespace OCA\X2Mail\Migration;

use OCA\X2Mail\AppInfo\Application;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Run on app enable and after upgrade.
 */
class InstallStep implements IRepairStep
{
	public function getName() {
		return 'Setup X2Mail';
	}

	public function run(IOutput $output): void {

		$output->info('clearstatcache');
		\clearstatcache();
		\clearstatcache(true);
		$output->info('opcache_reset');
		\opcache_reset();

		$output->info('Load App');
		\OCA\X2Mail\Util\SnappyMailHelper::loadApp();

		$output->info('Fix permissions');
		\SnappyMail\Upgrade::fixPermissions();

		$app_dir = \dirname(\dirname(__DIR__)) . '/app';

		if (!\file_exists($app_dir . '/.htaccess') && \file_exists($app_dir . '/_htaccess')) {
			\rename($app_dir . '/_htaccess', $app_dir . '/.htaccess');
		}
		if (!\file_exists(APP_VERSION_ROOT_PATH . 'app/.htaccess') && \file_exists(APP_VERSION_ROOT_PATH . 'app/_htaccess')) {
			\rename(APP_VERSION_ROOT_PATH . 'app/_htaccess', APP_VERSION_ROOT_PATH . 'app/.htaccess');
		}

		$oConfig = \RainLoop\Api::Config();
		$bSave = false;

		if (!$oConfig->Get('webmail', 'app_path')) {
			$output->info('Set config [webmail]app_path');
			$appWebPath = \OCP\Server::get(\OCP\App\IAppManager::class)->getAppWebPath('x2mail');
			$oConfig->Set('webmail', 'app_path', \rtrim($appWebPath, '/') . '/app/');
			$oConfig->Set('webmail', 'title', 'X2Mail');
			$oConfig->Set('webmail', 'loading_description', 'X2Mail');
			$oConfig->Set('webmail', 'allow_languages_on_settings', false);
			$oConfig->Set('login', 'allow_languages_on_login', false);
			$bSave = true;
		}

		// Sync bundled nextcloud plugin to SM data directory on every install/upgrade
		$bundledPlugin = $app_dir . '/snappymail/v/current/app/plugins/nextcloud';
		$installedPlugin = APP_PLUGINS_PATH . 'nextcloud';
		if (\is_dir($bundledPlugin)) {
			if (!\is_dir($installedPlugin)) {
				\mkdir($installedPlugin, 0755, true);
				$oConfig->Set('plugins', 'enable', true);
				$aList = \SnappyMail\Repository::getEnabledPackagesNames();
				$aList[] = 'nextcloud';
				$oConfig->Set('plugins', 'enabled_list', \implode(',', \array_unique($aList)));
				$oConfig->Set('webmail', 'theme', 'NextcloudV25+');
				$bSave = true;
			}
			// Always sync plugin files from bundled version
			$output->info('Sync bundled nextcloud plugin');
			foreach (new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($bundledPlugin, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::SELF_FIRST
			) as $item) {
				$relPath = \substr($item->getPathname(), \strlen($bundledPlugin));
				$dest = $installedPlugin . $relPath;
				if ($item->isDir()) {
					!\is_dir($dest) && \mkdir($dest, 0755, true);
				} else {
					\copy($item->getPathname(), $dest);
				}
			}
		}

		$sPassword = $oConfig->Get('security', 'admin_password');
		if ('12345' == $sPassword || !$sPassword) {
			$output->info('Generate admin password');
			$sPassword = \substr(\base64_encode(\random_bytes(16)), 0, 12);
			$oConfig->SetPassword(new \SnappyMail\SensitiveString($sPassword));
			\RainLoop\Utils::saveFile(APP_PRIVATE_DATA . 'admin_password.txt', $sPassword . "\n");
			$bSave = true;
		}

		// Remove SM default domains — X2Mail uses the Setup Wizard for domain config
		$smDefaults = ['gmail.com', 'hotmail.com', 'outlook.com', 'yahoo.com', 'nextcloud', 'default'];
		/** @var IConfig $ncConfig */
		$ncConfig = \OCP\Server::get(IConfig::class);
		$dataDir = \rtrim(\trim($ncConfig->getSystemValue('datadirectory', '')), '\\/');
		$domainsPath = $dataDir . '/appdata_x2mail/_data_/_default_/domains';
		if (\is_dir($domainsPath)) {
			foreach ($smDefaults as $name) {
				$file = $domainsPath . '/' . $name . '.json';
				if (\file_exists($file)) {
					\unlink($file);
					$output->info("Removed SM default domain: {$name}");
				}
			}
			// Remove SM internal hash domain (e.g. 76eaa44ce9a9.json)
			foreach (\glob($domainsPath . '/*.json') ?: [] as $file) {
				$basename = \basename($file, '.json');
				if (\preg_match('/\A[0-9a-f]{12}\z/', $basename)) {
					\unlink($file);
					$output->info("Removed SM hash domain: {$basename}");
				}
			}
		}

		if ($bSave) {
			$oConfig->Save()
				? $output->info('Config saved')
				: $output->info('Config failed');
		} else {
			$output->info('Config not changed');
		}

		// Check for custom initial config file
		try {
			/** @var IConfig $ncConfig */
			$ncConfig = \OCP\Server::get(IConfig::class);
			$customConfigFile = $ncConfig->getAppValue(Application::APP_ID, 'custom_config_file');
			if ($customConfigFile) {
				$output->info("Load custom config: {$customConfigFile}");
				// Security: restrict to appdata_x2mail/ directory
				$resolved = \realpath($customConfigFile);
				$allowedDir = \realpath($dataDir . '/appdata_x2mail');
				if ($resolved && $allowedDir && \str_starts_with($resolved, $allowedDir . '/')) {
					require $resolved;
				} else {
					throw new \Exception("custom config must be inside appdata_x2mail/");
				}
			}
		} catch (\Throwable $e) {
			$output->warning("custom config error: " . $e->getMessage());
			/** @var \Psr\Log\LoggerInterface $logger */
			$logger = \OCP\Server::get(\Psr\Log\LoggerInterface::class);
			$logger->error("custom config error: " . $e->getMessage());
		}
	}
}
