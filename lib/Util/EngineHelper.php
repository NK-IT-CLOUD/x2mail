<?php

namespace OCA\X2Mail\Util;

use OCP\App\IAppManager;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;

class EngineHelper
{
    public static function loadApp(): void
    {
        if (\class_exists('X2Mail\\Engine\\Api')) {
            return;
        }

        // X2Mail namespace autoloader (case-sensitive PSR-4 style)
        \spl_autoload_register(function ($sClassName) {
            if (\str_starts_with($sClassName, 'X2Mail\\')) {
                $file = X2MAIL_LIBRARIES_PATH . \strtr($sClassName, '\\', DIRECTORY_SEPARATOR) . '.php';
                if (\is_file($file)) {
                    include_once $file;
                }
            }
        });

        // Lowercase-filename autoloader for X2Mail\Engine
        \spl_autoload_register(function ($sClassName) {
            if (\str_starts_with($sClassName, 'X2Mail\\Engine\\')) {
                // Try fully lowercase path first
                $file = X2MAIL_LIBRARIES_PATH . 'X2Mail/Engine/'
                    . \strtolower(\strtr(\substr($sClassName, 14), '\\', DIRECTORY_SEPARATOR))
                    . '.php';
                if (\is_file($file)) {
                    include_once $file;
                    return;
                }
                // Try lowercase dirs with PascalCase filename
                $parts = \explode('\\', \substr($sClassName, 14));
                $fileName = \array_pop($parts);
                $dirPath = \implode(DIRECTORY_SEPARATOR, \array_map('strtolower', $parts));
                $file = X2MAIL_LIBRARIES_PATH . 'X2Mail/Engine/'
                    . ($dirPath ? $dirPath . DIRECTORY_SEPARATOR : '')
                    . $fileName . '.php';
                if (\is_file($file)) {
                    include_once $file;
                }
            }
        });

        $_ENV['X2MAIL_INCLUDE_AS_API'] = true;

        // Set data path BEFORE loading engine — otherwise it falls back to app/data/
        if (!\defined('APP_DATA_FOLDER_PATH')) {
            \define('APP_DATA_FOLDER_PATH', \rtrim(\trim(\OCP\Server::get(IConfig::class)->getSystemValue('datadirectory', '')), '\\/') . '/appdata_x2mail/');
        }

        $app_dir = \dirname(\dirname(__DIR__)) . '/app';
        $index = $app_dir . '/index.php';
        if (!\is_readable($index)) {
            \OCP\Server::get(\Psr\Log\LoggerInterface::class)
                ->warning('X2Mail: app/index.php not readable, skipping engine bootstrap');
            return;
        }
        require_once $index;
    }

    public static function startApp(bool $handle = false): void
    {
        static::loadApp();

        $oConfig = \X2Mail\Engine\Api::Config();

        if (false !== \stripos(\php_sapi_name(), 'cli')) {
            return;
        }

        try {
            $oActions = \X2Mail\Engine\Api::Actions();
            if (isset($_GET[$oConfig->Get('security', 'admin_panel_key', 'admin')])) {
                // Admin auth delegated to NC — IsAdminLoggined() checks NC admin status directly
            } else {
                $doLogin = !$oActions->getMainAccountFromToken(false);
                $aCredentials = self::getLoginCredentials();
                if ($doLogin && $aCredentials[1] && $aCredentials[2]) {
                    $isOIDC = \str_starts_with($aCredentials[2], 'oidc_login|');
                    try {
                        $oAccount = $oActions->LoginProcess($aCredentials[1], new \X2Mail\Engine\SensitiveString($aCredentials[2]));
                        if (
                            !$isOIDC
                            && $oAccount instanceof \X2Mail\Engine\Model\MainAccount
                            && $oConfig->Get('login', 'sign_me_auto', \X2Mail\Engine\Enumerations\SignMeType::DefaultOff) === \X2Mail\Engine\Enumerations\SignMeType::DefaultOn
                        ) {
                            $oActions->SetSignMeToken($oAccount);
                        }
                    } catch (\X2Mail\Engine\Exceptions\ClientException $e) {
                        // Only clear credentials on auth failure, not on connection errors
                        // (temporary IMAP outage should not wipe stored passwords)
                        if (!$isOIDC && $e->getCode() !== \X2Mail\Engine\Notifications::ConnectionError) {
                            $sUID = \OCP\Server::get(IUserSession::class)->getUser()->getUID();
                            \OCP\Server::get(ISession::class)->set('x2mail-passphrase', '');
                            \OCP\Server::get(IUserConfig::class)->setValueString($sUID, 'x2mail', 'passphrase', '');
                        }
                    } catch (\Throwable $e) {
                        // Non-login errors (e.g. DI failures) — don't touch credentials
                    }
                }
            }

            if ($handle) {
                \header_remove('Content-Security-Policy');
                \X2Mail\Engine\Service::Handle();
                exit;
            }
        } catch (\Throwable $e) {
            // Ignore login failure
        }
    }

    // Check if OpenID Connect (OIDC) is enabled and used for login
    public static function isOIDCLogin(): bool
    {
        $appConfig = \OCP\Server::get(IAppConfig::class);
        if ($appConfig->getValueString('x2mail', 'autologin-oidc', '0') !== '0') {
            // Check if either OIDC Login app or user_oidc app is enabled
            $appManager = \OCP\Server::get(IAppManager::class);
            if ($appManager->isEnabledForUser('oidc_login') || $appManager->isEnabledForUser('user_oidc')) {
                // Check if session is an OIDC Login
                $ocSession = \OCP\Server::get(ISession::class);
                if ($ocSession->get('is_oidc')) {
                    if ($ocSession->get('oidc_access_token')) {
                        return true;
                    }
                    \X2Mail\Engine\Log::debug('Nextcloud', 'OIDC access_token missing');
                } else {
                    \X2Mail\Engine\Log::debug('Nextcloud', 'No OIDC login');
                }
            } else {
                \X2Mail\Engine\Log::debug('Nextcloud', 'OIDC login disabled');
            }
        }
        return false;
    }

    /** @return array{string, string, string|\X2Mail\Engine\SensitiveString|null} */
    private static function getLoginCredentials(): array
    {
        $sUID = \OCP\Server::get(IUserSession::class)->getUser()->getUID();
        $appConfig = \OCP\Server::get(IAppConfig::class);
        $userConfig = \OCP\Server::get(IUserConfig::class);
        $ocSession = \OCP\Server::get(ISession::class);

        // If the user has set credentials for X2Mail in their personal settings,
        // this has the first priority.
        $sEmail = $userConfig->getValueString($sUID, 'x2mail', 'email');
        $sPassword = $userConfig->getValueString($sUID, 'x2mail', 'passphrase');
        if ($sEmail && $sPassword) {
            $sPassword = static::decodePassword($sPassword, \md5($sEmail));
            if ($sPassword) {
                return [$sUID, $sEmail, $sPassword];
            }
        }

        // If the current user ID is identical to login ID
        if ($ocSession->get('x2mail-uid') === $sUID) {
            // If OpenID Connect (OIDC) is enabled and used for login, use this.
            if (static::isOIDCLogin()) {
                $sEmail = $userConfig->getValueString($sUID, 'settings', 'email');
                return [$sUID, $sEmail, "oidc_login|{$sUID}"];
            }

            $sEmail = '';
            $sPassword = '';
            if ($appConfig->getValueString('x2mail', 'autologin', '0') !== '0') {
                $sEmail = $sUID;
                $sPassword = $ocSession->get('x2mail-passphrase');
            } elseif ($appConfig->getValueString('x2mail', 'autologin-with-email', '0') !== '0') {
                $sEmail = $userConfig->getValueString($sUID, 'settings', 'email');
                $sPassword = $ocSession->get('x2mail-passphrase');
            }
            if ($sPassword) {
                return [$sUID, $sEmail, static::decodePassword($sPassword, $sUID)];
            }
        }

        return [$sUID, '', ''];
    }

    public static function getAppUrl(): string
    {
        return \OCP\Server::get(IURLGenerator::class)->linkToRoute('x2mail.page.appGet');
    }

    public static function normalizeUrl(string $sUrl): string
    {
        $sUrl = \rtrim(\trim($sUrl), '/\\');
        if ('.php' !== \strtolower(\substr($sUrl, -4))) {
            $sUrl .= '/';
        }

        return $sUrl;
    }

    public static function encodePassword(string $sPassword, string $sSalt): string
    {
        static::loadApp();
        if (!\class_exists('X2Mail\\Engine\\Crypt', false)) {
            return '';
        }
        return \X2Mail\Engine\Crypt::EncryptUrlSafe($sPassword, $sSalt);
    }

    public static function decodePassword(string $sPassword, string $sSalt): ?\X2Mail\Engine\SensitiveString
    {
        static::loadApp();
        if (!\class_exists('X2Mail\\Engine\\Crypt', false)) {
            return null;
        }
        $result = \X2Mail\Engine\Crypt::DecryptUrlSafe($sPassword, $sSalt);
        return $result ? new \X2Mail\Engine\SensitiveString($result) : null;
    }
}
