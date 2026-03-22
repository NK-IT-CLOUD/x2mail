<?php

namespace OCA\X2Mail\Util;

use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;

class SnappyMailResponse extends \OCP\AppFramework\Http\Response
{
	public function render(): string
	{
		$data = '';
		$i = \ob_get_level();
		while ($i--) {
			$data .= \ob_get_clean();
		}
		return $data;
	}
}

class SnappyMailHelper
{

	public static function loadApp() : void
	{
		if (\class_exists('RainLoop\\Api')) {
			return;
		}

		// Nextcloud the default spl_autoload_register() not working
		\spl_autoload_register(function($sClassName){
			$file = SNAPPYMAIL_LIBRARIES_PATH . \strtolower(\strtr($sClassName, '\\', DIRECTORY_SEPARATOR)) . '.php';
			if (\is_file($file)) {
				include_once $file;
			}
		});

		$_ENV['SNAPPYMAIL_INCLUDE_AS_API'] = true;

		// Set data path BEFORE loading SM core — otherwise SM falls back to app/data/
		if (!\defined('APP_DATA_FOLDER_PATH')) {
			\define('APP_DATA_FOLDER_PATH', \rtrim(\trim(\OCP\Server::get(IConfig::class)->getSystemValue('datadirectory', '')), '\\/').'/appdata_x2mail/');
		}

		$app_dir = \dirname(\dirname(__DIR__)) . '/app';
		$index = $app_dir . '/index.php';
		if (!\is_readable($index)) {
			\OCP\Server::get(\Psr\Log\LoggerInterface::class)
				->warning('X2Mail: app/index.php not readable, skipping SM bootstrap');
			return;
		}
		require_once $index;
	}

	public static function startApp(bool $handle = false): void
	{
		static::loadApp();

		$oConfig = \RainLoop\Api::Config();

		if (false !== \stripos(\php_sapi_name(), 'cli')) {
			return;
		}

		try {
			$oActions = \RainLoop\Api::Actions();
			if (isset($_GET[$oConfig->Get('security', 'admin_panel_key', 'admin')])) {
				if ($oConfig->Get('security', 'allow_admin_panel', true)
				&& \OCP\Server::get(IGroupManager::class)->isAdmin(\OCP\Server::get(IUserSession::class)->getUser()->getUID())
				&& !$oActions->IsAdminLoggined(false)
				) {
					$sRand = \MailSo\Base\Utils::Sha1Rand();
					if ($oActions->Cacher(null, true)->Set(\RainLoop\KeyPathHelper::SessionAdminKey($sRand), (string) \time())) {
						$sToken = \RainLoop\Utils::EncodeKeyValuesQ(array('token', $sRand));
						\SnappyMail\Cookies::set('smadmin', $sToken);
					}
				}
			} else {
				$doLogin = !$oActions->getMainAccountFromToken(false);
				$aCredentials = self::getLoginCredentials();
				if ($doLogin && $aCredentials[1] && $aCredentials[2]) {
					$isOIDC = \str_starts_with($aCredentials[2], 'oidc_login|');
					try {
						$oAccount = $oActions->LoginProcess($aCredentials[1], new \SnappyMail\SensitiveString($aCredentials[2]));
						if (!$isOIDC
						 && $oAccount instanceof \RainLoop\Model\MainAccount
						 && $oConfig->Get('login', 'sign_me_auto', \RainLoop\Enumerations\SignMeType::DefaultOff) === \RainLoop\Enumerations\SignMeType::DefaultOn
						) {
							$oActions->SetSignMeToken($oAccount);
						}
					} catch (\RainLoop\Exceptions\ClientException $e) {
						// Only clear credentials on auth failure, not on connection errors
						// (temporary IMAP outage should not wipe stored passwords)
						if (!$isOIDC && $e->getCode() !== \RainLoop\Notifications::ConnectionError) {
							$sUID = \OCP\Server::get(IUserSession::class)->getUser()->getUID();
							\OCP\Server::get(ISession::class)->set('snappymail-passphrase', '');
							\OCP\Server::get(IConfig::class)->setUserValue($sUID, 'x2mail', 'passphrase', '');
						}
					} catch (\Throwable $e) {
						// Non-login errors (e.g. DI failures) — don't touch credentials
					}
				}
			}

			if ($handle) {
				\header_remove('Content-Security-Policy');
				\RainLoop\Service::Handle();
				exit;
			}
		} catch (\Throwable $e) {
			// Ignore login failure
		}
	}

	// Check if OpenID Connect (OIDC) is enabled and used for login
	public static function isOIDCLogin() : bool
	{
		$config = \OCP\Server::get(IConfig::class);
		if ($config->getAppValue('x2mail', 'snappymail-autologin-oidc', '0') !== '0') {
			// Check if either OIDC Login app or user_oidc app is enabled
			$appManager = \OCP\Server::get(IAppManager::class);
			if ($appManager->isEnabledForUser('oidc_login') || $appManager->isEnabledForUser('user_oidc')) {
				// Check if session is an OIDC Login
				$ocSession = \OCP\Server::get(ISession::class);
				if ($ocSession->get('is_oidc')) {
					if ($ocSession->get('oidc_access_token')) {
						return true;
					}
					\SnappyMail\Log::debug('Nextcloud', 'OIDC access_token missing');
				} else {
					\SnappyMail\Log::debug('Nextcloud', 'No OIDC login');
				}
			} else {
				\SnappyMail\Log::debug('Nextcloud', 'OIDC login disabled');
			}
		}
		return false;
	}

	/** @return array{string, string, string|\SnappyMail\SensitiveString|null} */
	private static function getLoginCredentials() : array
	{
		$sUID = \OCP\Server::get(IUserSession::class)->getUser()->getUID();
		$config = \OCP\Server::get(IConfig::class);
		$ocSession = \OCP\Server::get(ISession::class);

		// If the user has set credentials for SnappyMail in their personal settings,
		// this has the first priority.
		$sEmail = $config->getUserValue($sUID, 'x2mail', 'snappymail-email');
		$sPassword = $config->getUserValue($sUID, 'x2mail', 'passphrase')
			?: $config->getUserValue($sUID, 'x2mail', 'snappymail-password');
		if ($sEmail && $sPassword) {
			$sPassword = static::decodePassword($sPassword, \md5($sEmail));
			if ($sPassword) {
				return [$sUID, $sEmail, $sPassword];
			}
		}

		// If the current user ID is identical to login ID
		if ($ocSession->get('snappymail-nc-uid') === $sUID) {

			// If OpenID Connect (OIDC) is enabled and used for login, use this.
			if (static::isOIDCLogin()) {
				$sEmail = $config->getUserValue($sUID, 'settings', 'email');
				return [$sUID, $sEmail, "oidc_login|{$sUID}"];
			}

			$sEmail = '';
			$sPassword = '';
			if ($config->getAppValue('x2mail', 'snappymail-autologin', '0') !== '0') {
				$sEmail = $sUID;
				$sPassword = $ocSession->get('snappymail-passphrase');
			} else if ($config->getAppValue('x2mail', 'snappymail-autologin-with-email', '0') !== '0') {
				$sEmail = $config->getUserValue($sUID, 'settings', 'email');
				$sPassword = $ocSession->get('snappymail-passphrase');
			}
			if ($sPassword) {
				return [$sUID, $sEmail, static::decodePassword($sPassword, $sUID)];
			}
		}

		return [$sUID, '', ''];
	}

	public static function getAppUrl() : string
	{
		return \OCP\Server::get(IURLGenerator::class)->linkToRoute('x2mail.page.appGet');
	}

	public static function normalizeUrl(string $sUrl) : string
	{
		$sUrl = \rtrim(\trim($sUrl), '/\\');
		if ('.php' !== \strtolower(\substr($sUrl, -4))) {
			$sUrl .= '/';
		}

		return $sUrl;
	}

	public static function encodePassword(string $sPassword, string $sSalt) : string
	{
		static::loadApp();
		if (!\class_exists('SnappyMail\\Crypt', false)) {
			return '';
		}
		return \SnappyMail\Crypt::EncryptUrlSafe($sPassword, $sSalt);
	}

	public static function decodePassword(string $sPassword, string $sSalt) : ?\SnappyMail\SensitiveString
	{
		static::loadApp();
		if (!\class_exists('SnappyMail\\Crypt', false)) {
			return null;
		}
		$result = \SnappyMail\Crypt::DecryptUrlSafe($sPassword, $sSalt);
		return $result ? new \SnappyMail\SensitiveString($result) : null;
	}
}
