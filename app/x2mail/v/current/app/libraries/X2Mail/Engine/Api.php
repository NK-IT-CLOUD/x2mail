<?php

namespace X2Mail\Engine;

abstract class Api
{

	public static function Actions() : Actions
	{
		static $oActions = null;
		if (null === $oActions) {
			$oActions = new Actions();
		}

		return $oActions;
	}

	public static function Config() : Config\Application
	{
		static $oConfig = null;
		if (!$oConfig) {
			$oConfig = new Config\Application();
			if (!$oConfig->Load()) {
				\usleep(10000);
				$oConfig->Load();
			}
//			\ini_set('display_errors', '0');
			if ($oConfig->Get('debug', 'enable', false)) {
				\error_reporting(E_ALL);
//				\ini_set('display_errors', '1');
				\ini_set('log_errors', '1');
			}
			\X2Mail\Mail\Config::$BoundaryPrefix = \trim($oConfig->Get('labs', 'boundary_prefix', ''));
		}
		return $oConfig;
	}

	public static function getCSP(?string $sScriptNonce = null) : \X2Mail\Engine\HTTP\CSP
	{
		$oConfig = static::Config();
		$CSP = new \X2Mail\Engine\HTTP\CSP(\trim($oConfig->Get('security', 'content_security_policy', '')));
		$CSP->report = $oConfig->Get('security', 'csp_report', false);
		$CSP->report_only = $oConfig->Get('debug', 'enable', false); // || X2MAIL_DEV

		// Allow https: due to remote images in e-mails or use proxy
		if (!$oConfig->Get('labs', 'use_local_proxy_for_external_images', '')) {
			$CSP->add('img-src', 'https:');
			$CSP->add('img-src', 'http:');
		}
		if ($sScriptNonce) {
			$CSP->add('script-src', "'nonce-{$sScriptNonce}'");
		}

		static::Actions()->Plugins()->RunHook('main.content-security-policy', array($CSP));

		return $CSP;
	}

	public static function Logger() : \X2Mail\Mail\Log\Logger
	{
		static $oLogger = null;
		if (!$oLogger) {
			$oConfig = static::Config();
			$oLogger = new \X2Mail\Mail\Log\Logger(true);
			$oLogger->SetShowSecrets(!$oConfig->Get('logs', 'hide_passwords', true));
			if ($oConfig->Get('debug', 'enable', false)) {
				$oLogger->SetLevel(\LOG_DEBUG);
			} else if ($oConfig->Get('logs', 'enable', false)) {
				$oLogger->SetLevel(\max(3, \X2Mail\Engine\Api::Config()->Get('logs', 'level', \LOG_WARNING)));
			}
		}
		return $oLogger;
	}

	public static function Version() : string
	{
		return APP_VERSION;
	}

	public static function CreateUserSsoHash(string $sEmail,
		#[\SensitiveParameter]
		string $sPassword,
		array $aAdditionalOptions = array(), bool $bUseTimeout = true
	) : ?string
	{
		$sSsoHash = \X2Mail\Mail\Base\Utils::Sha1Rand(\sha1($sPassword.$sEmail));

		return static::Actions()->Cacher()->Set(
			KeyPathHelper::SsoCacherKey($sSsoHash),
			\X2Mail\Engine\Crypt::EncryptToJSON(array(
				'Email' => $sEmail,
				'Password' => $sPassword,
				'AdditionalOptions' => $aAdditionalOptions,
				'Time' => $bUseTimeout ? \time() : 0
			), $sSsoHash)
		) ? $sSsoHash : null;
	}

	public static function ClearUserSsoHash(string $sSsoHash) : bool
	{
		return static::Actions()->Cacher()->Delete(KeyPathHelper::SsoCacherKey($sSsoHash));
	}

	public static function ClearUserData(string $sEmail) : bool
	{
		if (\strlen($sEmail)) {
			$sEmail = \X2Mail\Engine\IDN::emailToAscii($sEmail);

			$oStorageProvider = static::Actions()->StorageProvider();
			if ($oStorageProvider && $oStorageProvider->IsActive()) {
				$oStorageProvider->DeleteStorage($sEmail);
			}

			$oConfig = static::Config();
			$sqlite_global = $oConfig->Get('contacts', 'sqlite_global', false);
			if ('sqlite' != $oConfig->Get('contacts', 'type', '') || \is_file(APP_PRIVATE_DATA . '/AddressBook.sqlite')) {
				$oConfig->Set('contacts', 'sqlite_global', true);
				$oAddressBookProvider = static::Actions()->AddressBookProvider();
				$oAddressBookProvider && $oAddressBookProvider->DeleteAllContacts($sEmail);
				$oConfig->Set('contacts', 'sqlite_global', !!$sqlite_global);
			}

			return true;
		}

		return false;
	}

	public static function LogoutCurrentLogginedUser() : bool
	{
		static::Actions()->Logout(true);
		return true;
	}
}
