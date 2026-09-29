<?php

namespace X2Mail\Engine;

class ServiceActions
{
	/**
	 * Json actions the client may address by URL path with GET. Both only read.
	 */
	private const GET_ACTIONS = ['MessageList', 'Message'];

	protected \X2Mail\Mail\Base\Http $oHttp;

	protected Actions $oActions;

	protected array $aPaths = array();

	protected string $sQuery = '';

	public function __construct(\X2Mail\Mail\Base\Http $oHttp, Actions $oActions)
	{
		$this->oHttp = $oHttp;
		$this->oActions = $oActions;
	}

	private function Logger() : \X2Mail\Mail\Log\Logger
	{
		return $this->oActions->Logger();
	}

	private function Plugins() : Plugins\Manager
	{
		return $this->oActions->Plugins();
	}

	private function Config() : Config\Application
	{
		return $this->oActions->Config();
	}

	private function Cacher() : \X2Mail\Mail\Cache\CacheClient
	{
		return $this->oActions->Cacher();
	}

	private function StorageProvider() : Providers\Storage
	{
		return $this->oActions->StorageProvider();
	}

	private function SettingsProvider() : Providers\Settings
	{
		return $this->oActions->SettingsProvider();
	}

	public function SetPaths(array $aPaths) : self
	{
		$this->aPaths = $aPaths;
		return $this;
	}

	public function SetQuery(string $sQuery) : self
	{
		$this->sQuery = $sQuery;
		return $this;
	}


	public function ServiceJson() : string
	{
		\ob_start();

		$aResponse = null;
		$oException = null;

		if (empty($_POST) || (!empty($_SERVER['CONTENT_TYPE']) && \str_contains($_SERVER['CONTENT_TYPE'], 'application/json'))) {
			$_POST = \json_decode(\file_get_contents('php://input'), true);
		}

		$sAction = $_POST['Action'] ?? '';
		$bPathAction = false;
		if (empty($sAction) && $this->oHttp->IsGet() && !empty($this->aPaths[2])) {
			$sAction = $this->aPaths[2];
			$bPathAction = true;
		}

		$this->oActions->SetIsJson(true);

		try
		{
			if (empty($sAction)) {
				throw new Exceptions\ClientException(Notifications::InvalidInputArgument->value, null, 'Action unknown');
			}

			// A GET must not change state (RFC 9110 §9.2.1), and any element in a
			// mail body can trigger one. Only read-only actions may come from the path.
			if ($bPathAction && !\in_array($sAction, self::GET_ACTIONS, true)) {
				throw new Exceptions\ClientException(Notifications::InvalidInputArgument->value, null, 'Action not allowed via GET');
			}

			// Every action except Logout needs the token, whatever the HTTP method.
			if ('Logout' !== $sAction) {
				$token = Utils::GetCsrfToken();
				if (isset($_SERVER['HTTP_X_SM_TOKEN'])) {
					if ($_SERVER['HTTP_X_SM_TOKEN'] !== $token) {
						$oAccount = $this->oActions->getAccountFromToken(false);
						$sEmail = $oAccount ? $oAccount->Email() : 'guest';
						$this->oActions->logWrite("{$_SERVER['HTTP_X_SM_TOKEN']} !== {$token} for {$sEmail}", \LOG_ERR, 'Token');
						throw new Exceptions\ClientException(Notifications::InvalidToken->value, null, 'HTTP Token mismatch');
					}
				} else if (empty($_POST['XToken']) || $_POST['XToken'] !== $token) {
					$oAccount = $this->oActions->getAccountFromToken(false);
					$sEmail = $oAccount ? $oAccount->Email() : 'guest';
					$this->oActions->logWrite(($_POST['XToken'] ?? '') . " !== {$token} for {$sEmail}", \LOG_ERR, 'XToken');
					throw new Exceptions\ClientException(Notifications::InvalidToken->value, null, 'XToken mismatch');
				}
			}

			$sMethodName = 'Do'.$sAction;

			$this->oActions->logWrite('Action: '.$sMethodName, \LOG_INFO, 'JSON');

			if ($_POST) {
				$this->oActions->SetActionParams($_POST, $sMethodName);
				$aPost = $_POST;
				foreach ($aPost as $key => $value) {
					// password & passphrase
					if (false !== \stripos($key, 'pass')) {
						$aPost[$key] = '*******';
//						$this->oActions->logMask($value);
					}
				}
				$this->oActions->logWrite(Utils::jsonEncode($aPost), \LOG_INFO, 'POST');
			} else if (3 < \count($this->aPaths) && $this->oHttp->IsGet()) {
				$this->oActions->SetActionParams(array(
					'RawKey' => empty($this->aPaths[3]) ? '' : $this->aPaths[3]
				), $sMethodName);
			}

			if (\method_exists($this->oActions, $sMethodName) && \is_callable(array($this->oActions, $sMethodName))) {
				$this->Plugins()->RunHook("json.before-{$sAction}");
				$aResponse = $this->oActions->{$sMethodName}();
			} else if ($this->Plugins()->HasAdditionalJson($sMethodName)) {
				$this->Plugins()->RunHook("json.before-{$sAction}");
				$aResponse = $this->Plugins()->RunAdditionalJson($sMethodName);
			}

			if (\is_array($aResponse)) {
				// Everything must converted to array
				$aResponse = \json_decode(Utils::jsonEncode($aResponse), true);
				$this->Plugins()->RunHook("json.after-{$sAction}", array(&$aResponse));
			}

			if (!\is_array($aResponse)) {
				throw new Exceptions\ClientException(Notifications::UnknownError->value);
			}
		}
		catch (\Throwable $oException)
		{
			\X2Mail\Engine\Log::warning('SERVICE', "{$oException}");
			if ($e = $oException->getPrevious()) {
				\X2Mail\Engine\Log::warning('SERVICE', "- {$e}");
			}

			$aResponse = $this->oActions->ExceptionResponse($oException);
		}

		$aResponse['Action'] = $sAction ?: 'Unknown';

		if (!\headers_sent()) {
			\header('Content-Type: application/json; charset=utf-8');
		}

		if (\is_array($aResponse)) {
			$aResponse['epoch'] = \time();
//			if ($this->Config()->Get('debug', 'enable', false)) {
//				$aResponse['rtime'] = \round(\microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 3);
//			}
		}

		$sResult = Utils::jsonEncode($aResponse);

		$sObResult = \ob_get_clean();

		if ($this->Logger()->IsEnabled()) {
			if (\strlen($sObResult)) {
				$this->oActions->logWrite($sObResult, \LOG_ERR, 'OB-DATA');
			}

			if ($oException) {
				$this->oActions->logException($oException, \LOG_ERR);
			}

			$iLimit = (int) $this->Config()->Get('logs', 'json_response_write_limit', 0);
			$this->oActions->logWrite(0 < $iLimit && $iLimit < \strlen($sResult)
					? \substr($sResult, 0, $iLimit).'...' : $sResult, \LOG_INFO, 'JSON');
		}

		return $sResult;
	}

	private function privateUpload(string $sAction, int $iSizeLimit = 0) : string
	{
		$oConfig = $this->Config();

		\ob_start();
		$aResponse = null;
		try
		{
			$sToken = $_SERVER['HTTP_X_SM_TOKEN'] ?? $_POST['XToken'] ?? '';
			if (!\is_string($sToken) || $sToken !== Utils::GetCsrfToken()) {
				throw new Exceptions\ClientException(Notifications::InvalidToken->value, null, 'Upload token mismatch');
			}

			$aFile = null;
			$sInputName = 'uploader';
			$iSizeLimit = (0 < $iSizeLimit ? $iSizeLimit : ((int) $oConfig->Get('webmail', 'attachment_size_limit', 0))) * 1024 * 1024;

			/** @phpstan-ignore isset.variable */
			$_FILES = isset($_FILES) ? $_FILES : null;
			if (isset($_FILES[$sInputName], $_FILES[$sInputName]['name'], $_FILES[$sInputName]['tmp_name'], $_FILES[$sInputName]['size'])) {
				$iError = (isset($_FILES[$sInputName]['error'])) ? (int) $_FILES[$sInputName]['error'] : UPLOAD_ERR_OK;
//				\is_uploaded_file($_FILES[$sInputName]['tmp_name'])

				if (UPLOAD_ERR_OK === $iError && 0 < $iSizeLimit && $iSizeLimit < (int) $_FILES[$sInputName]['size']) {
					$iError = Enumerations\UploadError::CONFIG_SIZE->value;
				}

				if (UPLOAD_ERR_OK === $iError) {
					$aFile = $_FILES[$sInputName];
				}
			} else if (empty($_FILES)) {
				$iError = UPLOAD_ERR_INI_SIZE;
			} else {
				$iError = Enumerations\UploadError::EMPTY_FILE->value;
			}

			if (\method_exists($this->oActions, $sAction) && \is_callable(array($this->oActions, $sAction))) {
				$aResponse = $this->oActions->{$sAction}($aFile, $iError);
			}

			if (!is_array($aResponse)) {
				throw new Exceptions\ClientException(Notifications::UnknownError->value);
			}

			$this->Plugins()->RunHook('filter.upload-response', array(&$aResponse));
		}
		catch (\Throwable $oException)
		{
			$aResponse = $this->oActions->ExceptionResponse($oException);
		}

		$aResponse['Action'] = $sAction ?: 'Unknown';

		\header('Content-Type: application/json; charset=utf-8');

		$sResult = Utils::jsonEncode($aResponse);

		$sObResult = \ob_get_clean();
		if (\strlen($sObResult)) {
			$this->oActions->logWrite($sObResult, \LOG_ERR, 'OB-DATA');
		}

		$this->oActions->logWrite($sResult, \LOG_INFO, 'UPLOAD');

		return $sResult;
	}

	public function ServiceUpload() : string
	{
		return $this->privateUpload('Upload');
	}

	public function ServiceUploadContacts() : string
	{
		return $this->privateUpload('UploadContacts', 5);
	}

	public function ServiceUploadBackground() : string
	{
		return $this->privateUpload('UploadBackground', 1);
	}

	public function ServiceProxyExternal() : string
	{
		$sData = empty($this->aPaths[1]) ? '' : $this->aPaths[1];
		if ($sData && $this->Config()->Get('labs', 'use_local_proxy_for_external_images', false)) {
			$this->oActions->verifyCacheByKey($sData);
			$sUrl = \X2Mail\Mail\Base\Utils::UrlSafeBase64Decode($sData);
			if (!empty($sUrl)) {
				\header('X-Content-Location: '.$sUrl);
				$tmp = \tmpfile();
				$HTTP = \X2Mail\Engine\HTTP\Request::factory();
				$HTTP->max_redirects = 2;
				$HTTP->block_private = true; // SSRF guard for user-supplied image URLs (S3)
				$HTTP->streamBodyTo($tmp);
				$oResponse = $HTTP->doRequest('GET', $sUrl);
				if ($oResponse) {
					$sContentType = \X2Mail\Engine\File\MimeType::fromStream($tmp) ?: $oResponse->getHeader('content-type');
					if (200 === $oResponse->status && \str_starts_with($sContentType, 'image/')) {
						try {
							$this->oActions->cacheByKey($sData);
							\header('Content-Type: ' . $sContentType);
							\header('Cache-Control: public');
							\header('Expires: '.\gmdate('D, j M Y H:i:s', 2592000 + \time()).' UTC');
							\header('X-Content-Redirect-Location: '.$oResponse->final_uri);
							\rewind($tmp);
							\fpassthru($tmp);
							exit;
						} catch (\Throwable $e) {
							\header("X-Content-Error: {$e->getMessage()}");
							\X2Mail\Engine\Log::error('Proxy', \get_class($HTTP) . ': ' . $e->getMessage());
						}
					} else {
						\header("X-Content-Error: {$oResponse->status} {$sContentType}");
					}
				}
			}
		}

		\X2Mail\Mail\Base\Http::StatusHeader(404);
		return '';
	}

	public function ServiceCspReport() : void
	{
		\X2Mail\Engine\HTTP\CSP::logReport();
	}

	public function ServiceRaw() : string
	{
		$sResult = '';
		$sRawError = '';
		$sAction = empty($this->aPaths[2]) ? '' : $this->aPaths[2];
		$oException = null;

		try
		{
			$sRawError = 'Invalid action';
			if (\strlen($sAction)) {
				try {
					$sMethodName = 'Raw'.$sAction;
					if (\method_exists($this->oActions, $sMethodName)) {
						\header('X-Raw-Action: '.$sMethodName);
						\header('Content-Security-Policy: script-src \'none\'; child-src \'none\'');

						$sRawError = '';
						$this->oActions->SetActionParams(array(
							'RawKey' => empty($this->aPaths[3]) ? '' : $this->aPaths[3],
							'Params' => $this->aPaths
						), $sMethodName);

						if (!$this->oActions->{$sMethodName}()) {
							$sRawError = 'False result';
						}
					} else {
						$sRawError = 'Unknown action "'.$sAction.'"';
					}
				} catch (\Throwable $e) {
//					error_log(print_r($e,1));
					$sRawError = $e->getMessage();
				}
			} else {
				$sRawError = 'Empty action';
			}
		}
		catch (Exceptions\ClientException $oException)
		{
			$sRawError = Notifications::AuthError->value == $oException->getCode()
				? 'Authentication failed'
				: 'Exception as result';
		}
		catch (\Throwable $oException)
		{
			$sRawError = 'Exception as result';
		}

		if (\strlen($sRawError)) {
			$this->oActions->logWrite($sRawError, \LOG_ERR);
			$this->Logger()->WriteDump($this->aPaths, \LOG_ERR, 'PATHS');
		}

		if ($oException) {
			$this->oActions->logException($oException, \LOG_ERR, 'RAW');
		}

		return $sResult;
	}

	public function ServiceLang() : string
	{
		$sResult = '';
		\header('Content-Type: application/javascript; charset=utf-8');

		if (!empty($this->aPaths[3])) {
			$sLanguage = $this->oActions->ValidateLanguage($this->aPaths[3], '', false);

			$bCacheEnabled = $this->Config()->Get('cache', 'system_data', true);
			$sCacheFileName = '';
			if ($bCacheEnabled) {
				$sCacheFileName = KeyPathHelper::LangCache($sLanguage, false, $this->oActions->Plugins()->Hash());
				$this->oActions->verifyCacheByKey(\md5($sCacheFileName));
				$sResult = $this->Cacher()->Get($sCacheFileName);
			}

			if (!$sResult) {
				$sResult = $this->oActions->compileLanguage($sLanguage, false);
				if ($sCacheFileName) {
					$this->Cacher()->Set($sCacheFileName, $sResult);
				}
			}

			if ($sCacheFileName) {
				$this->oActions->cacheByKey(\md5($sCacheFileName));
			}
		}

		return $sResult;
	}

	public function ServicePlugins() : string
	{
		$sResult = '';

		\header('Content-Type: application/javascript; charset=utf-8');

		$bAppDebug = $this->Config()->Get('debug', 'enable', false);

		$bCacheEnabled = !$bAppDebug && $this->Config()->Get('cache', 'system_data', true);
		$sCacheFileName = '';
		if ($bCacheEnabled) {
			$sCacheFileName = KeyPathHelper::PluginsJsCache($this->oActions->Plugins()->Hash());
			$this->oActions->verifyCacheByKey(\md5($sCacheFileName));
			$sResult = $this->Cacher()->Get($sCacheFileName);
		}

		if (!$sResult) {
			$sResult = $this->Plugins()->CompileJs(false);
			if ($sCacheFileName) {
				$this->Cacher()->Set($sCacheFileName, $sResult);
			}
		}

		if ($sCacheFileName) {
			$this->oActions->cacheByKey(\md5($sCacheFileName));
		}

		return $sResult;
	}

	public function ServiceCss() : string
	{
		$sResult = '';
		$bJson = !empty($this->aPaths[9]) && 'Json' === $this->aPaths[9];

		if ($bJson) {
			\header('Content-Type: application/json; charset=utf-8');
		} else {
			\header('Content-Type: text/css; charset=utf-8');
		}

		$sTheme = '';
		if (!empty($this->aPaths[4])) {
			$sTheme = $this->oActions->ValidateTheme($this->aPaths[4]);

			$bAppDebug = $this->Config()->Get('debug', 'enable', false);

			$bCacheEnabled = !$bAppDebug && $this->Config()->Get('cache', 'system_data', true);
			$sCacheFileName = '';
			if ($bCacheEnabled) {
				$sCacheFileName = '/CssCache/'.$this->oActions->Plugins()->Hash().'/'.$sTheme.'/'.APP_VERSION.'/';
				$this->oActions->verifyCacheByKey(\md5($sCacheFileName . ($bJson ? 1 : 0)));
				$sResult = $this->Cacher()->Get($sCacheFileName);
			}

			if (!$sResult) {
				try
				{
					$sResult = $this->oActions->compileCss($sTheme, false);
					if ($sCacheFileName) {
						$this->Cacher()->Set($sCacheFileName, $sResult);
					}
				}
				catch (\Throwable $oException)
				{
					$this->oActions->logException($oException, \LOG_ERR, 'LESS');
				}
			}

			if ($sCacheFileName) {
				$this->oActions->cacheByKey(\md5($sCacheFileName . ($bJson ? 1 : 0 )));
			}
		}

		return $bJson ? Utils::jsonEncode(array($sTheme, $sResult)) : $sResult;
	}

	public function ServiceAppData() : string
	{
		return $this->localAppData();
	}

	public function ServiceMailto() : string
	{
		$this->oHttp->ServerNoCache();
		$sTo = \trim($_GET['to'] ?? '');
		if (\preg_match('/^mailto:/i', $sTo)) {
			\X2Mail\Engine\Cookies::set(
				Actions::AUTH_MAILTO_TOKEN_KEY,
				Utils::EncodeKeyValuesQ(array(
					'Time' => \microtime(true),
					'MailTo' => 'MailTo',
					'To' => $sTo
				))
			);
		}
		\X2Mail\Mail\Base\Http::Location('./');
		return '';
	}

	public function ServicePing() : string
	{
		$this->oHttp->ServerNoCache();

		\header('Content-Type: text/plain; charset=utf-8');
		$this->oActions->logWrite('Pong', \LOG_INFO, 'PING');
		return 'Pong';
	}

	public function ServiceTest() : string
	{
		$this->oHttp->ServerNoCache();
		\X2Mail\Engine\Integrity::test();
		return '';
	}

	/**
	 * Login with the \X2Mail\Engine\API::CreateUserSsoHash() generated hash
	 */
	public function ServiceSso() : string
	{
		$this->oHttp->ServerNoCache();

		$oException = null;
		$oAccount = null;

		$sSsoHash = $_REQUEST['hash'] ?? '';
		if (!empty($sSsoHash)) {
			$mData = null;

			$sSsoSubData = $this->Cacher()->Get(KeyPathHelper::SsoCacherKey($sSsoHash));
			if (!empty($sSsoSubData)) {
				$aData = \X2Mail\Engine\Crypt::DecryptFromJSON($sSsoSubData, $sSsoHash);

				$this->Cacher()->Delete(KeyPathHelper::SsoCacherKey($sSsoHash));

				if (\is_array($aData) && !empty($aData['Email']) && isset($aData['Password'], $aData['Time']) &&
					(\time() - 10 < $aData['Time']))
				{
					$aAdditionalOptions = (isset($aData['AdditionalOptions']) && \is_array($aData['AdditionalOptions']))
						? $aData['AdditionalOptions'] : [];
					try
					{
						$oAccount = $this->oActions->LoginProcess(
							\trim($aData['Email']),
							new \X2Mail\Engine\SensitiveString($aData['Password'])
						);
						if ($aAdditionalOptions) {
							$bSaveSettings = false;

							$oSettings = $this->SettingsProvider()->Load($oAccount);
							if ($oSettings) {
								$sLanguage = isset($aAdditionalOptions['language']) ?
									$aAdditionalOptions['language'] : '';

								if ($sLanguage) {
									$sLanguage = $this->oActions->ValidateLanguage($sLanguage);
									if ($sLanguage !== $oSettings->GetConf('language', '')) {
										$bSaveSettings = true;
										$oSettings->SetConf('language', $sLanguage);
									}
								}
							}

							if ($bSaveSettings) {
								$oSettings->save();
							}
						}
					}
					catch (\Throwable $oException)
					{
						$this->oActions->logException($oException);
					}
				}
			}
		}

		\X2Mail\Mail\Base\Http::Location('./');
		return '';
	}

	public function ErrorTemplates(string $sTitle, string $sDesc, bool $bShowBackLink = true) : string
	{
		return \strtr(\file_get_contents(APP_VERSION_ROOT_PATH.'app/templates/Error.html'), array(
			'{{ErrorTitle}}' => $sTitle,
			'{{ErrorHeader}}' => $sTitle,
			'{{ErrorDesc}}' => $sDesc,
			'{{BackLinkVisibilityStyle}}' => $bShowBackLink ? 'display:inline-block' : 'display:none',
			'{{BackLink}}' => $this->oActions->StaticI18N('BACK_LINK'),
			'{{BackHref}}' => './'
		));
	}

	private function localError(string $sTitle, string $sDesc) : string
	{
		\header('Content-Type: text/html; charset=utf-8');
		return $this->ErrorTemplates($sTitle, \nl2br($sDesc));
	}

	private function localAppData() : string
	{
		\header('Content-Type: application/json; charset=utf-8');
		$this->oHttp->ServerNoCache();
		try {
			return Utils::jsonEncode($this->oActions->AppData(false));
		} catch (\Throwable $oException) {
			$this->Logger()->WriteExceptionShort($oException);
			\X2Mail\Mail\Base\Http::StatusHeader(500);
			return $oException->getMessage();
		}
	}

	public function compileTemplates() : string
	{
		$aTemplates = array();

		foreach (['Components', 'User', 'Common'] as $dir) {
			$sNameSuffix = ('Components' === $dir) ? 'Component' : '';
			foreach (\glob(APP_VERSION_ROOT_PATH."app/templates/Views/{$dir}/*.html") as $file) {
				$sTemplateName = \basename($file, '.html') . $sNameSuffix;
				$aTemplates[$sTemplateName] = $file;
			}
		}

		$this->oActions->Plugins()->CompileTemplate($aTemplates, false);

		$sHtml = '';
		foreach ($aTemplates as $sName => $sFile) {
			$sName = \preg_replace('/[^a-zA-Z0-9]/', '', $sName);
			$sHtml .= '<template id="'.$sName.'">'
				. \preg_replace('/<(\/?)script/i', '<$1x-script', \file_get_contents($sFile))
				. '</template>';
		}

		return \str_replace('&nbsp;', "\xC2\xA0", $sHtml);
	}
}
