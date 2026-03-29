<?php

namespace OCA\X2Mail\Migration;

use OCA\X2Mail\AppInfo\Application;
use OCA\X2Mail\Util\EngineHelper;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Config\IUserConfig;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Run on app enable and after upgrade.
 */
class InstallStep implements IRepairStep
{
    public function __construct(
        private IAppManager $appManager,
        private IAppConfig $appConfig,
        private IConfig $config,
        private IUserConfig $userConfig,
        private LoggerInterface $logger,
        private EngineHelper $engineHelper,
    ) {
    }

    public function getName()
    {
        return 'Setup X2Mail';
    }

    public function run(IOutput $output): void
    {
        // Migrate legacy snappymail-* appconfig keys to new names (v0.6.0)
        $keyMap = [
            'snappymail-autologin-oidc' => 'autologin-oidc',
            'snappymail-autologin' => 'autologin',
            'snappymail-autologin-with-email' => 'autologin-with-email',
        ];
        foreach ($keyMap as $oldKey => $newKey) {
            $oldVal = $this->appConfig->getValueString('x2mail', $oldKey, '');
            if ($oldVal !== '') {
                $newVal = $this->appConfig->getValueString('x2mail', $newKey, '');
                if ($newVal === '') {
                    $this->appConfig->setValueString('x2mail', $newKey, $oldVal);
                    $output->info("Migrated appconfig key: {$oldKey} → {$newKey}");
                }
                $this->appConfig->deleteKey('x2mail', $oldKey);
            }
        }

        $output->info('clearstatcache');
        \clearstatcache();
        \clearstatcache(true);
        $output->info('opcache_reset');
        \opcache_reset();

        $output->info('Load App');
        $this->engineHelper->loadApp();

        $output->info('Fix permissions');
        \X2Mail\Engine\Upgrade::fixPermissions();

        $app_dir = \dirname(\dirname(__DIR__)) . '/app';

        if (!\file_exists($app_dir . '/.htaccess') && \file_exists($app_dir . '/_htaccess')) {
            \rename($app_dir . '/_htaccess', $app_dir . '/.htaccess');
        }
        $versionRoot = APP_VERSION_ROOT_PATH;
        if (!\file_exists($versionRoot . 'app/.htaccess') && \file_exists($versionRoot . 'app/_htaccess')) {
            \rename($versionRoot . 'app/_htaccess', $versionRoot . 'app/.htaccess');
        }

        $oConfig = \X2Mail\Engine\Api::Config();
        $bSave = false;

        // X2Mail defaults — SSO-first, enforced on every install/upgrade
        $oConfig->Set('webmail', 'title', 'X2Mail');
        $oConfig->Set('webmail', 'loading_description', 'X2Mail');
        $oConfig->Set('webmail', 'theme', 'x2mail');
        $oConfig->Set('webmail', 'allow_themes', false);
        $oConfig->Set('webmail', 'allow_languages_on_settings', false);
        $oConfig->Set('webmail', 'allow_additional_accounts', false);
        $oConfig->Set('webmail', 'allow_additional_identities', true);
        $oConfig->Set('login', 'allow_languages_on_login', false);
        $oConfig->Set('login', 'sign_me_auto', \X2Mail\Engine\Enumerations\SignMeType::Unused);
        $oConfig->Set('imap', 'show_login_alert', false);
        $oConfig->Set('defaults', 'autologout', 15);
        $oConfig->Set('defaults', 'contacts_autosave', false);
        $oConfig->Set('contacts', 'enable', true);
        $oConfig->Set('contacts', 'allow_sync', true);
        $oConfig->Set('plugins', 'enable', true);
        $oConfig->Set('security', 'custom_server_signature', 'X2Mail');
        $oConfig->Set('admin_panel', 'allow_update', false);

        // Fix legacy contacts DSN if it still references old dbname
        $dsn = $oConfig->Get('contacts', 'pdo_dsn', '');
        if (\str_contains($dsn, 'dbname=snappymail')) {
            $oConfig->Set('contacts', 'pdo_dsn', \str_replace('dbname=snappymail', 'dbname=x2mail', $dsn));
        }

        if (!$oConfig->Get('webmail', 'app_path')) {
            $output->info('Set config [webmail]app_path');
            $appWebPath = $this->appManager->getAppWebPath('x2mail');
            $appPath = \preg_replace('#(?<!:)/+#', '/', \rtrim($appWebPath, '/') . '/app/');
            $oConfig->Set('webmail', 'app_path', $appPath);
        }

        $bSave = true;

        // Sync bundled nextcloud plugin to engine data directory on every install/upgrade
        $bundledPlugin = $app_dir . '/x2mail/v/current/app/plugins/nextcloud';
        $installedPlugin = APP_PLUGINS_PATH . 'nextcloud';
        if (\is_dir($bundledPlugin)) {
            if (!\is_dir($installedPlugin)) {
                \mkdir($installedPlugin, 0755, true);
                $oConfig->Set('plugins', 'enable', true);
                $aList = \X2Mail\Engine\Repository::getEnabledPackagesNames();
                $aList[] = 'nextcloud';
                $oConfig->Set('plugins', 'enabled_list', \implode(',', \array_unique($aList)));
                $bSave = true;
            }
            // Always sync plugin files from bundled version
            $output->info('Sync bundled nextcloud plugin');
            foreach (
                new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($bundledPlugin, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST
                ) as $item
            ) {
                $relPath = \substr($item->getPathname(), \strlen($bundledPlugin));
                $dest = $installedPlugin . $relPath;
                if ($item->isDir()) {
                    !\is_dir($dest) && \mkdir($dest, 0755, true);
                } else {
                    \copy($item->getPathname(), $dest);
                }
            }
        }

        // Remove legacy admin password file if present
        $passfile = APP_PRIVATE_DATA . 'admin_password.txt';
        if (\is_file($passfile)) {
            \unlink($passfile);
        }

        $oConfig->Save()
            ? $output->info('Config saved')
            : $output->info('Config failed');

        // Check for custom initial config file
        try {
            $customConfigFile = $this->appConfig->getValueString(Application::APP_ID, 'custom_config_file');
            if ($customConfigFile) {
                $output->info("Load custom config: {$customConfigFile}");
                // Security: restrict to appdata_x2mail/ directory
                $resolved = \realpath($customConfigFile);
                $dataDir = \rtrim(\trim($this->config->getSystemValue('datadirectory', '')), '\\/');
                $allowedDir = \realpath($dataDir . '/appdata_x2mail');
                if ($resolved && $allowedDir && \str_starts_with($resolved, $allowedDir . '/')) {
                    require $resolved;
                } else {
                    throw new \Exception("custom config must be inside appdata_x2mail/");
                }
            }
        } catch (\Throwable $e) {
            $output->warning("custom config error: " . $e->getMessage());
            $this->logger->error("custom config error: " . $e->getMessage());
        }

        // Clear legacy Engine\Crypt passwords — ICrypto format is incompatible (v0.6.1)
        try {
            $this->userConfig->deleteKey('x2mail', 'passphrase');
            $output->info('Cleared legacy password storage (re-encrypted on next login)');
        } catch (\Throwable $e) {
            // Non-fatal — users will re-authenticate
        }
    }
}
