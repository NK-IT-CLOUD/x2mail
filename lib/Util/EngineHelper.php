<?php

namespace OCA\X2Mail\Util;

use OCP\App\IAppManager;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

class EngineHelper
{
    public function __construct(
        private IConfig $config,
        private IAppConfig $appConfig,
        private IUserConfig $userConfig,
        private ISession $session,
        private IUserSession $userSession,
        private IAppManager $appManager,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
        private ICrypto $crypto,
    ) {
    }

    public function loadApp(): void
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
                $file = X2MAIL_LIBRARIES_PATH . 'X2Mail/Engine/'
                    . \strtolower(\strtr(\substr($sClassName, 14), '\\', DIRECTORY_SEPARATOR))
                    . '.php';
                if (\is_file($file)) {
                    include_once $file;
                    return;
                }
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

        if (!\defined('APP_DATA_FOLDER_PATH')) {
            $dataDir = \rtrim(\trim($this->config->getSystemValue('datadirectory', '')), '\\/');
            \define('APP_DATA_FOLDER_PATH', $dataDir . '/appdata_x2mail/');
        }

        $app_dir = \dirname(\dirname(__DIR__)) . '/app';
        $index = $app_dir . '/index.php';
        if (!\is_readable($index)) {
            $this->logger->warning('X2Mail: app/index.php not readable, skipping engine bootstrap');
            return;
        }
        require_once $index;
    }

    public function startApp(bool $handle = false): void
    {
        $this->loadApp();

        $oConfig = \X2Mail\Engine\Api::Config();

        if (false !== \stripos(\php_sapi_name(), 'cli')) {
            return;
        }

        try {
            $oActions = \X2Mail\Engine\Api::Actions();
            if (isset($_GET[$oConfig->Get('security', 'admin_panel_key', 'admin')])) {
                // Admin auth delegated to NC
            } else {
                $doLogin = !$oActions->getMainAccountFromToken(false);
                $aCredentials = $this->getLoginCredentials();
                if ($doLogin && $aCredentials[1] && $aCredentials[2]) {
                    $isOIDC = \str_starts_with($aCredentials[2], 'oidc_login|');
                    try {
                        $oAccount = $oActions->LoginProcess(
                            $aCredentials[1],
                            new \X2Mail\Engine\SensitiveString($aCredentials[2])
                        );
                        $signMeDefault = \X2Mail\Engine\Enumerations\SignMeType::DefaultOff;
                        $signMeOn = \X2Mail\Engine\Enumerations\SignMeType::DefaultOn;
                        if (
                            !$isOIDC
                            && $oAccount instanceof \X2Mail\Engine\Model\MainAccount
                            && $oConfig->Get('login', 'sign_me_auto', $signMeDefault) === $signMeOn
                        ) {
                            $oActions->SetSignMeToken($oAccount);
                        }
                    } catch (\X2Mail\Engine\Exceptions\ClientException $e) {
                        if (!$isOIDC && $e->getCode() !== \X2Mail\Engine\Notifications::ConnectionError->value) {
                            $sUID = $this->userSession->getUser()->getUID();
                            $this->session->set('x2mail-passphrase', '');
                            $this->userConfig->setValueString($sUID, 'x2mail', 'passphrase', '');
                        }
                    } catch (\Throwable $e) {
                        // Non-login errors — don't touch credentials
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

    public function isOIDCLogin(): bool
    {
        if ($this->appConfig->getValueString('x2mail', 'autologin-oidc', '0') !== '0') {
            if ($this->appManager->isEnabledForUser('oidc_login') || $this->appManager->isEnabledForUser('user_oidc')) {
                if ($this->session->get('is_oidc')) {
                    if ($this->session->get('oidc_access_token')) {
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

    /** @return array{string, string, string|null} */
    private function getLoginCredentials(): array
    {
        $sUID = $this->userSession->getUser()->getUID();

        $sEmail = $this->userConfig->getValueString($sUID, 'x2mail', 'email');
        $sPassword = $this->userConfig->getValueString($sUID, 'x2mail', 'passphrase');
        if ($sEmail && $sPassword) {
            $sPassword = $this->decodePassword($sPassword, \md5($sEmail));
            if ($sPassword) {
                return [$sUID, $sEmail, $sPassword];
            }
        }

        if ($this->session->get('x2mail-uid') === $sUID) {
            if ($this->isOIDCLogin()) {
                $sEmail = $this->userConfig->getValueString($sUID, 'settings', 'email');
                return [$sUID, $sEmail, "oidc_login|{$sUID}"];
            }

            $sEmail = '';
            $sPassword = '';
            $autologin = $this->appConfig->getValueString('x2mail', 'autologin', '0') !== '0';
            $autologinEmail = $this->appConfig->getValueString('x2mail', 'autologin-with-email', '0') !== '0';
            if ($autologin || $autologinEmail) {
                $sEmail = $this->userConfig->getValueString($sUID, 'settings', 'email') ?: $sUID;
                $sPassword = $this->session->get('x2mail-passphrase');
            }
            if ($sPassword) {
                return [$sUID, $sEmail, $this->decodePassword($sPassword, $sUID)];
            }
        }

        return [$sUID, '', ''];
    }

    public function getAppUrl(): string
    {
        return $this->urlGenerator->linkToRoute('x2mail.page.appGet');
    }

    public function normalizeUrl(string $sUrl): string
    {
        $sUrl = \rtrim(\trim($sUrl), '/\\');
        if ('.php' !== \strtolower(\substr($sUrl, -4))) {
            $sUrl .= '/';
        }
        return $sUrl;
    }

    public function encodePassword(string $sPassword, string $sSalt): string
    {
        return $this->crypto->encrypt($sPassword, $sSalt);
    }

    public function decodePassword(string $sPassword, string $sSalt): ?string
    {
        try {
            return $this->crypto->decrypt($sPassword, $sSalt);
        } catch (\Exception $e) {
            return null;
        }
    }
}
