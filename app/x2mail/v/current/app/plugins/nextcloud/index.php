<?php

class NextcloudPlugin extends \X2Mail\Engine\Plugins\AbstractPlugin
{
	const
		NAME = 'Nextcloud',
		VERSION = '2.39.0',
		RELEASE  = '2024-10-08',
		CATEGORY = 'Integrations',
		DESCRIPTION = 'Integrate with Nextcloud v20+',
		REQUIRED = '2.38.0';

	public function Init() : void
	{
		if (static::IsIntegrated()) {
			\X2Mail\Engine\Log::debug('Nextcloud', 'integrated');
			$this->UseLangs(true);

			$this->addHook('main.fabrica', 'MainFabrica');
			$this->addHook('filter.app-data', 'FilterAppData');
			$this->addHook('filter.language', 'FilterLanguage');

			$this->addCss('style.css');

			$this->addJs('js/webdav.js');

			$this->addJs('js/message.js');
			$this->addHook('json.attachments', 'DoAttachmentsActions');
			$this->addJsonHook('NextcloudSaveMsg', 'NextcloudSaveMsg');

			$this->addJs('js/composer.js');
			$this->addJsonHook('NextcloudAttachFile', 'NextcloudAttachFile');

			$this->addJs('js/messagelist.js');

			$this->addTemplate('templates/PopupsNextcloudFiles.html');
			$this->addTemplate('templates/PopupsNextcloudCalendars.html');

//			$this->addHook('login.credentials.step-2', 'loginCredentials2');
//			$this->addHook('login.credentials', 'loginCredentials');
			$this->addHook('imap.before-login', 'beforeLogin');
			$this->addHook('smtp.before-login', 'beforeLogin');
			$this->addHook('sieve.before-login', 'beforeLogin');
		} else {
			\X2Mail\Engine\Log::debug('Nextcloud', 'NOT integrated');
			// \OC::$server->getConfig()->getAppValue('x2mail', 'x2mail-no-embed');
			$this->addHook('main.content-security-policy', 'ContentSecurityPolicy');
		}
	}

	public function ContentSecurityPolicy(\X2Mail\Engine\HTTP\CSP $CSP)
	{
		if (\method_exists($CSP, 'add')) {
			$CSP->add('frame-ancestors', "'self'");
		}
	}

	public function Supported() : string
	{
		return static::IsIntegrated() ? '' : 'Nextcloud not found to use this plugin';
	}

	public static function IsIntegrated()
	{
		return \class_exists('OC') && isset(\OC::$server);
	}

	public static function IsLoggedIn()
	{
		return static::IsIntegrated() && \OCP\Server::get(\OCP\IUserSession::class)->isLoggedIn();
	}

	public function loginCredentials(string &$sEmail, string &$sLogin, ?string &$sPassword = null) : void
	{
		/**
		 * This has an issue.
		 * When user changes email address, all settings are gone as the new
		 * _data_/_default_/storage/{domain}/{local-part} is used
		 */
//		$ocUser = \OC::$server->getUserSession()->getUser();
//		$sEmail = $ocUser->getEMailAddress() ?: $ocUser->getPrimaryEMailAddress() ?: $sEmail;
	}

	public function loginCredentials2(string &$sEmail, ?string &$sPassword = null) : void
	{
		$ocUser = \OCP\Server::get(\OCP\IUserSession::class)->getUser();
		$sEmail = $ocUser->getEMailAddress() ?: $ocUser->getPrimaryEMailAddress() ?: $sEmail;
	}

	public function beforeLogin(\X2Mail\Engine\Model\Account $oAccount, \X2Mail\Mail\Net\NetClient $oClient, \X2Mail\Mail\Net\ConnectSettings $oSettings) : void
	{
		// Only login with OIDC access token if
		// it is enabled in config, the user is currently logged in with OIDC,
		// the current X2Mail account is the OIDC account and no account defined explicitly
		if ($oAccount instanceof \X2Mail\Engine\Model\MainAccount
		 && \OCP\Server::get(\OCA\X2Mail\Util\EngineHelper::class)->isOIDCLogin()
		 && \str_starts_with($oSettings->passphrase, 'oidc_login|')
		) {
			$oSettings->passphrase = \OCP\Server::get(\OCP\ISession::class)->get('oidc_access_token');
			\array_unshift($oSettings->SASLMechanisms, 'OAUTHBEARER');
		}
	}

	/*
	\OC::$server->getCalendarManager();
	\OC::$server->getLDAPProvider();
	*/

	private static function getUserFolder(): ?\OCP\Files\Folder
	{
		$user = \OCP\Server::get(\OCP\IUserSession::class)->getUser();
		if (!$user) {
			return null;
		}
		return \OCP\Server::get(\OCP\Files\IRootFolder::class)
			->getUserFolder($user->getUID());
	}

	public function NextcloudAttachFile() : array
	{
		$aResult = [
			'success' => false,
			'tempName' => ''
		];
		$sFile = $this->jsonParam('file', '');
		if (\str_contains($sFile, '..') || \str_contains($sFile, "\0")) {
			return $this->jsonResponse(__FUNCTION__, $aResult);
		}
		$userFolder = static::getUserFolder();
		if ($userFolder && $userFolder->nodeExists($sFile)) {
			$node = $userFolder->get($sFile);
			if ($node instanceof \OCP\Files\File && $fp = $node->fopen('rb')) {
				$oActions = \X2Mail\Engine\Api::Actions();
				$oAccount = $oActions->getAccountFromToken();
				if ($oAccount) {
					$sSavedName = 'nextcloud-file-' . \sha1($sFile . \microtime());
					if (!$oActions->FilesProvider()->PutFile($oAccount, $sSavedName, $fp)) {
						$aResult['error'] = 'failed';
					} else {
						$aResult['tempName'] = $sSavedName;
						$aResult['success'] = true;
					}
				}
			}
		}
		return $this->jsonResponse(__FUNCTION__, $aResult);
	}

	public function NextcloudSaveMsg() : array
	{
		$sSaveFolder = \ltrim($this->jsonParam('folder', ''), '/');
//		$aValues = \X2Mail\Engine\Api::Actions()->decodeRawKey($this->jsonParam('msgHash', ''));
		$msgHash = $this->jsonParam('msgHash', '');
		$aValues = \json_decode(\X2Mail\Mail\Base\Utils::UrlSafeBase64Decode($msgHash), true);
		$aResult = [
			'folder' => '',
			'filename' => '',
			'success' => false
		];
		if (\str_contains($sSaveFolder, '..') || \str_contains($sSaveFolder, "\0")) {
			return $this->jsonResponse(__FUNCTION__, $aResult);
		}
		if ($sSaveFolder && !empty($aValues['folder']) && !empty($aValues['uid'])) {
			$oActions = \X2Mail\Engine\Api::Actions();
			$oMailClient = $oActions->MailClient();
			if (!$oMailClient->IsLoggined()) {
				$oAccount = $oActions->getAccountFromToken();
				$oAccount->ImapConnectAndLogin($oActions->Plugins(), $oMailClient->ImapClient(), $oActions->Config());
			}

			$sSaveFolder = $sSaveFolder ?: 'Emails';
			$userFolder = static::getUserFolder();
			$saveFolder = $userFolder?->getOrCreateFolder($sSaveFolder);
			$aResult['folder'] = $sSaveFolder;
			$aResult['filename'] = \X2Mail\Mail\Base\Utils::SecureFileName(
				\mb_substr($this->jsonParam('filename', '') ?: \date('YmdHis'), 0, 100)
			) . '.' . \md5($msgHash) . '.eml';

			$oMailClient->MessageMimeStream(
				function ($rResource) use ($saveFolder, &$aResult) {
					if (\is_resource($rResource) && $saveFolder) {
						$saveFolder->newFile($aResult['filename'], $rResource);
						$aResult['success'] = true;
					}
				},
				(string) $aValues['folder'],
				(int) $aValues['uid'],
				isset($aValues['mimeIndex']) ? (string) $aValues['mimeIndex'] : ''
			);
		}

		return $this->jsonResponse(__FUNCTION__, $aResult);
	}

	public function DoAttachmentsActions(\X2Mail\Engine\AttachmentsAction $data)
	{
		if (static::isLoggedIn() && 'nextcloud' === $data->action) {
			$userFolder = static::getUserFolder();
			if ($userFolder) {
				$sSaveFolder = \ltrim($this->jsonParam('NcFolder', ''), '/');
				if (\str_contains($sSaveFolder, '..') || \str_contains($sSaveFolder, "\0")) {
					return;
				}
				$sSaveFolder = $sSaveFolder ?: 'Attachments';
				$saveFolder = $userFolder->getOrCreateFolder($sSaveFolder);
				$data->result = true;
				foreach ($data->items as $aItem) {
					$sSavedFileName = empty($aItem['fileName']) ? 'file.dat' : $aItem['fileName'];
					$sUniqueName = $saveFolder->getNonExistingName($sSavedFileName);
					if (!empty($aItem['data'])) {
						$saveFolder->newFile($sUniqueName, $aItem['data']);
					} else if (!empty($aItem['fileHash'])) {
						$fFile = $data->filesProvider->GetFile($data->account, $aItem['fileHash'], 'rb');
						if (\is_resource($fFile)) {
							$saveFolder->newFile($sUniqueName, $fFile);
							if (\is_resource($fFile)) {
								\fclose($fFile);
							}
						}
					}
				}
			}
		}
	}

	public function FilterAppData($bAdmin, &$aResult) : void
	{
		if (!$bAdmin && \is_array($aResult)) {
			$ocUser = \OCP\Server::get(\OCP\IUserSession::class)->getUser();
			$sUID = $ocUser->getUID();
			$oUrlGen = \OCP\Server::get(\OCP\IURLGenerator::class);
			$sWebDAV = $oUrlGen->getAbsoluteURL($oUrlGen->linkTo('', 'remote.php') . '/dav');
//			$sWebDAV = \OCP\Util::linkToRemote('dav');
			$aResult['Nextcloud'] = [
				'UID' => $sUID,
				'WebDAV' => $sWebDAV,
				'CalDAV' => $this->Config()->Get('plugin', 'calendar', false)
//				'WebDAV_files' => $sWebDAV . '/files/' . $sUID
			];
			if (empty($aResult['Auth'])) {
				$config = \OCP\Server::get(\OCP\IConfig::class);
				$sEmail = '';
				if ($config->getAppValue('x2mail', 'autologin', false)
					|| $config->getAppValue('x2mail', 'autologin-with-email', false)) {
					// Always use NC profile email, never bare UID
					$sEmail = $config->getUserValue($sUID, 'settings', 'email', '')
						?: $ocUser->getEMailAddress()
						?: $sUID;
				} else {
					\X2Mail\Engine\Log::debug('Nextcloud', 'autologin is off');
				}
				$sCustomEmail = $config->getUserValue($sUID, 'x2mail', 'email', '');
				if ($sCustomEmail) {
					$sEmail = $sCustomEmail;
				}
				if (!$sEmail) {
					$sEmail = $ocUser->getEMailAddress();
				}
/*
				if ($config->getAppValue('x2mail', 'autologin-oidc', false)) {
					if (\OC::$server->getSession()->get('is_oidc')) {
						$sEmail = "{$sUID}@nextcloud";
						$aResult['DevPassword'] = \OC::$server->getSession()->get('oidc_access_token');
					} else {
						\X2Mail\Engine\Log::debug('Nextcloud', 'Not an OIDC login');
					}
				} else {
					\X2Mail\Engine\Log::debug('Nextcloud', 'OIDC is off');
				}
*/
				$aResult['DevEmail'] = $sEmail ?: '';
			}
		}
	}

	public function FilterLanguage(&$sLanguage, $bAdmin) : void
	{
		if (!\X2Mail\Engine\Api::Config()->Get('webmail', 'allow_languages_on_settings', true)) {
			$aResultLang = \X2Mail\Engine\L10n::getLanguages($bAdmin);
			$userId = \OCP\Server::get(\OCP\IUserSession::class)->getUser()->getUID();
			$userLang = \OCP\Server::get(\OCP\IConfig::class)->getUserValue($userId, 'core', 'lang', 'en');
			$userLang = \strtr($userLang, '_', '-');
			$sLanguage = $this->determineLocale($userLang, $aResultLang);
			// Check if $sLanguage is null
			if (!$sLanguage) {
				$sLanguage = 'en'; // Assign 'en' if $sLanguage is null
			}
		}
	}

	/**
	 * Determine locale from user language.
	 *
	 * @param string $langCode The name of the input.
	 * @param array  $languagesArray The value of the array.
	 *
	 * @return string return locale
	 */
	private function determineLocale(string $langCode, array $languagesArray) : ?string
	{
		// Direct check for the language code
		if (\in_array($langCode, $languagesArray)) {
			return $langCode;
		}

		// Check without country code
		if (\str_contains($langCode, '-')) {
			$langCode = \explode('-', $langCode)[0];
			if (\in_array($langCode, $languagesArray)) {
				return $langCode;
			}
		}

		// Check with uppercase country code
		$langCodeWithUpperCase = $langCode . '-' . \strtoupper($langCode);
		if (\in_array($langCodeWithUpperCase, $languagesArray)) {
			return $langCodeWithUpperCase;
		}

		// If no match is found
		return null;
	}

	/**
	 * @param mixed $mResult
	 */
	public function MainFabrica(string $sName, &$mResult)
	{
		if (static::isLoggedIn()) {
			if ('address-book' === $sName) {
				include_once __DIR__ . '/NextcloudAddressBook.php';
				$mResult = new \NextcloudAddressBook();
			}
		}
	}

	protected function configMapping() : array
	{
		return array(
			\X2Mail\Engine\Plugins\Property::NewInstance('calendar')->SetLabel('Enable "Put ICS in calendar"')
				->SetType(\X2Mail\Engine\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(false)
		);
	}

}
