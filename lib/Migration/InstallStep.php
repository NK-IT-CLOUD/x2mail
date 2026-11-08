<?php

declare(strict_types=1);

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

        // Keep post-update changes narrow: migrate legacy/unsafe values without resetting admin customizations.
        $this->applyReleaseDefaults($oConfig, $output);
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

        // Clean-sync bundled nextcloud plugin to engine data directory
        $bundledPlugin = $app_dir . '/x2mail/v/current/app/plugins/nextcloud';
        $installedPlugin = APP_PLUGINS_PATH . 'nextcloud';
        if (\is_dir($bundledPlugin)) {
            if (!(bool) $oConfig->Get('plugins', 'enable', false)) {
                $oConfig->Set('plugins', 'enable', true);
                $output->info('Enabled plugins support for bundled nextcloud integration');
            }

            // Ensure plugin is registered
            $aList = \array_values(\array_filter(
                \array_map('trim', \explode(',', (string) $oConfig->Get('plugins', 'enabled_list', '')))
            ));
            if (!\in_array('nextcloud', $aList)) {
                $aList[] = 'nextcloud';
                $oConfig->Set('plugins', 'enabled_list', \implode(',', \array_unique($aList)));
                $output->info('Enabled bundled nextcloud plugin');
            }

            // Delete old plugin dir to prevent stale files
            if (\is_dir($installedPlugin)) {
                $output->info('Clean installed plugin dir');
                $this->recursiveDelete($installedPlugin);
            }

            // Copy fresh from bundled version
            $output->info('Sync bundled nextcloud plugin');
            \mkdir($installedPlugin, 0755, true);
            foreach (
                new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($bundledPlugin, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST
                ) as $item
            ) {
                $relPath = \substr($item->getPathname(), \strlen($bundledPlugin));
                $dest = $installedPlugin . $relPath;
                if ($item->isDir()) {
                    \mkdir($dest, 0755, true);
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

        // Clear legacy Engine\Crypt passwords once — ICrypto format is incompatible (v0.6.1)
        try {
            $migrationKey = 'migration-passphrase-cleared-v061';
            if ($this->appConfig->getValueString(Application::APP_ID, $migrationKey, '0') !== '1') {
                $this->userConfig->deleteKey('x2mail', 'passphrase');
                $this->appConfig->setValueString(Application::APP_ID, $migrationKey, '1');
                $output->info('Cleared legacy password storage (re-encrypted on next login)');
            }
        } catch (\Throwable $e) {
            // Non-fatal — users will re-authenticate
        }
    }

    /**
     * Apply X2Mail defaults only when values still match legacy or raw engine defaults.
     */
    private function applyReleaseDefaults(object $config, IOutput $output): void
    {
        /** @var \X2Mail\Engine\Config\Application $config */
        $this->setIfCurrentIn(
            $config,
            'webmail',
            'title',
            'X2Mail',
            ['', 'SnappyMail', 'RainLoop'],
            $output,
            'Updated webmail title to X2Mail',
        );
        $this->setIfCurrentIn(
            $config,
            'webmail',
            'loading_description',
            'X2Mail',
            ['', 'SnappyMail', 'RainLoop'],
            $output,
            'Updated loading description to X2Mail',
        );
        $this->setIfCurrentIn(
            $config,
            'webmail',
            'theme',
            'x2mail',
            ['', 'Default', 'NextcloudV25+'],
            $output,
            'Migrated legacy theme to x2mail',
        );
        $this->setIfCurrentIn(
            $config,
            'webmail',
            'allow_additional_identities',
            true,
            [false],
            $output,
            'Enabled additional identities for X2Mail defaults',
        );
        $this->setIfCurrentIn(
            $config,
            'security',
            'custom_server_signature',
            'X2Mail',
            ['', 'SnappyMail', 'RainLoop'],
            $output,
            'Updated legacy server signature',
        );
        $this->setIfCurrentIn(
            $config,
            'imap',
            'show_login_alert',
            false,
            [true],
            $output,
            'Disabled IMAP login alert for release defaults',
        );
        $this->setIfCurrentIn(
            $config,
            'defaults',
            'autologout',
            0,
            [30, 15],
            $output,
            'Turned the idle logout off (release default)',
        );
        $this->setIfCurrentIn(
            $config,
            'defaults',
            'contacts_autosave',
            false,
            [true],
            $output,
            'Disabled contacts autosave for release defaults',
        );
    }

    /**
     * @param list<string|bool|int> $legacyValues
     * @param string|bool|int $newValue
     */
    /**
     * @param object $config Engine config with Get()/Set() methods
     * @param list<string|bool|int> $legacyValues
     * @param string|bool|int $newValue
     */
    private function setIfCurrentIn(
        object $config,
        string $section,
        string $key,
        string|bool|int $newValue,
        array $legacyValues,
        IOutput $output,
        string $message,
    ): void {
        /** @var \X2Mail\Engine\Config\Application $config */
        $currentValue = $config->Get($section, $key);
        if (\in_array($currentValue, $legacyValues, true)) {
            $config->Set($section, $key, $newValue);
            $output->info($message);
        }
    }

    private function recursiveDelete(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? \rmdir($item->getPathname()) : \unlink($item->getPathname());
        }
        \rmdir($dir);
    }
}
