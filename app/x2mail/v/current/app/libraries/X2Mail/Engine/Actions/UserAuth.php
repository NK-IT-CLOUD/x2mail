<?php

namespace X2Mail\Engine\Actions;

use X2Mail\Engine\Enumerations\Capa;
use X2Mail\Engine\Notifications;
use X2Mail\Engine\Utils;
use X2Mail\Engine\Model\Account;
use X2Mail\Engine\Model\MainAccount;
use X2Mail\Engine\Model\AdditionalAccount;
use X2Mail\Engine\Providers\Storage\Enumerations\StorageType;
use X2Mail\Engine\Exceptions\ClientException;
use X2Mail\Engine\Cookies;
use X2Mail\Engine\SensitiveString;

trait UserAuth
{
	/**
	 * @var bool | null | Account
	 */
	private $oAdditionalAuthAccount = false;
	private $oMainAuthAccount = false;

	public function DoResealCryptKey() : array
	{
		return $this->DefaultResponse(
			$this->getMainAccountFromToken()->resealCryptKey(
				new SensitiveString($this->GetActionParam('passphrase', ''))
			)
		);
	}

	/**
	 * @throws \X2Mail\Engine\Exceptions\ClientException
	 */
	protected function resolveLoginCredentials(string $sEmail, SensitiveString $oPassword): array
	{
		$sEmail = \X2Mail\Engine\IDN::emailToAscii(\X2Mail\Mail\Base\Utils::Trim($sEmail));

		$sNewEmail = $sEmail;
		$this->Plugins()->RunHook('login.credentials.step-1', array(&$sNewEmail));
		if ($sNewEmail) {
			$sEmail = $sNewEmail;
		}

		$oDomain = null;
		$oDomainProvider = $this->DomainProvider();

		// When email address is missing the domain, try to add it
		if (!\str_contains($sEmail, '@')) {
			$this->logWrite("The email address '{$sEmail}' is incomplete", \LOG_INFO, 'LOGIN');
			if ($this->Config()->Get('login', 'determine_user_domain', false)) {
				$sUserHost = \X2Mail\Engine\IDN::toAscii($this->Http()->GetHost(false, true));
				$this->logWrite("Determined user domain: {$sUserHost}", \LOG_INFO, 'LOGIN');

				// Determine without wildcard
				$aDomainParts = \explode('.', $sUserHost);
				$iLimit = \min(\count($aDomainParts), 14);
				while (0 < $iLimit--) {
					$sDomain = \implode('.', $aDomainParts);
					$oDomain = $oDomainProvider->Load($sDomain, false);
					if ($oDomain) {
						$sEmail .= '@' . $sDomain;
						$this->logWrite("Check '{$sDomain}': OK", \LOG_INFO, 'LOGIN');
						break;
					} else {
						$this->logWrite("Check '{$sDomain}': NO", \LOG_INFO, 'LOGIN');
					}
					\array_shift($aDomainParts);
				}

				// Else determine with wildcard
				if (!$oDomain) {
					$oDomain = $oDomainProvider->Load($sUserHost, true);
					if ($oDomain) {
						$sEmail .= '@' . $sUserHost;
						$this->logWrite("Check '{$sUserHost}' with wildcard: OK", \LOG_INFO, 'LOGIN');
					} else {
						$this->logWrite("Check '{$sUserHost}' with wildcard: NO", \LOG_INFO, 'LOGIN');
					}
				}

				if (!$oDomain) {
					$this->logWrite("Domain '{$sUserHost}' was not determined!", \LOG_INFO, 'LOGIN');
				}
			}

			// Else try default domain
			if (!$oDomain) {
				$sDefDomain = \trim($this->Config()->Get('login', 'default_domain', ''));
				if (\strlen($sDefDomain)) {
					if ('HTTP_HOST' === $sDefDomain || 'SERVER_NAME' === $sDefDomain) {
						$sDefDomain = \preg_replace('/:[0-9]+$/D', '', $_SERVER[$sDefDomain]);
					} else if ('gethostname' === $sDefDomain) {
						$sDefDomain = \gethostname();
					}
					$sEmail .= '@' . $sDefDomain;
					$this->logWrite("Default domain '{$sDefDomain}' will be used.", \LOG_INFO, 'LOGIN');
				} else {
					$this->logWrite('Default domain not configured.', \LOG_INFO, 'LOGIN');
				}
			}
		}

		$sNewEmail = $sEmail;
		$sPassword = $oPassword->getValue();
		$this->Plugins()->RunHook('login.credentials.step-2', array(&$sNewEmail, &$sPassword));
		$this->logMask($sPassword);
		if ($sNewEmail) {
			$sEmail = $sNewEmail;
		}

		$sImapUser = $sEmail;
		$sSmtpUser = $sEmail;
		if (\str_contains($sEmail, '@')
		 && ($oDomain || ($oDomain = $oDomainProvider->Load(\X2Mail\Mail\Base\Utils::getEmailAddressDomain($sEmail), true)))
		) {
			$sEmail = $oDomain->ImapSettings()->fixUsername($sEmail, false);
			$sImapUser = $oDomain->ImapSettings()->fixUsername($sImapUser);
			$sSmtpUser = $oDomain->SmtpSettings()->fixUsername($sSmtpUser);
		}

		$sNewEmail = $sEmail;
		$sNewImapUser = $sImapUser;
		$sNewSmtpUser = $sSmtpUser;
		$this->Plugins()->RunHook('login.credentials', array(&$sNewEmail, &$sNewImapUser, &$sPassword, &$sNewSmtpUser));

		$oPassword->setValue($sPassword);

		return [
			'email' => $sNewEmail ?: $sEmail,
			'domain' => $oDomain,
			'imapUser' => $sNewImapUser ?: $sImapUser,
			'smtpUser' => $sNewSmtpUser ?: $sSmtpUser,
			'pass' => $oPassword
		];
	}

	/**
	 * @throws \X2Mail\Engine\Exceptions\ClientException
	 */
	public function LoginProcess(string $sEmail, SensitiveString $oPassword, bool $bMainAccount = true): Account
	{
		$aCredentials = $this->resolveLoginCredentials($sEmail, $oPassword);

		if (!\str_contains($aCredentials['email'], '@') || !\strlen($oPassword)) {
			throw new ClientException(Notifications::InvalidInputArgument->value);
		}

		$oDomain = $this->DomainProvider()->getByEmailAddress($aCredentials['email']);

		$oAccount = null;
		try {
			$oAccount = $bMainAccount ? new MainAccount : new AdditionalAccount;
			$oAccount->setCredentials(
				$aCredentials['domain'],
				$aCredentials['email'],
				$aCredentials['imapUser'],
				$oPassword,
				$aCredentials['smtpUser']
//				,new SensitiveString($oPassword)
			);
			$this->Plugins()->RunHook('filter.account', array($oAccount));
			if (!$oAccount) {
				throw new ClientException(Notifications::AccountFilterError->value);
			}
		} catch (\Throwable $oException) {
			$this->LoggerAuthHelper($oAccount, $sEmail);
			throw $oException;
		}

		$this->imapConnect($oAccount, true);
		if ($bMainAccount) {
			$this->StorageProvider()->Put($oAccount, StorageType::SESSION->value, Utils::GetSessionToken(), 'true');

			// Must be here due to bug #1241
			$this->SetMainAuthAccount($oAccount);
			$this->Plugins()->RunHook('login.success', array($oAccount));

			$this->SetAuthToken($oAccount);
			$this->SetAdditionalAuthToken(null);
		}

		return $oAccount;
	}

	public function switchAccount(string $sEmail) : bool
	{
		$this->Http()->ServerNoCache();
		$oMainAccount = $this->getMainAccountFromToken(false);
		if ($sEmail && $oMainAccount && $this->GetCapa(Capa::ADDITIONAL_ACCOUNTS->value)) {
			$oAccount = null;
			if ($oMainAccount->Email() !== $sEmail) {
				$sEmail = \X2Mail\Engine\IDN::emailToAscii($sEmail);
				$aAccounts = $this->GetAccounts($oMainAccount);
				if (!isset($aAccounts[$sEmail])) {
					throw new ClientException(Notifications::AccountDoesNotExist->value);
				}
				try {
					$oAccount = AdditionalAccount::NewInstanceFromTokenArray(
						$this, $aAccounts[$sEmail], true
					);
				} catch (\Throwable $e) {
					throw new ClientException(Notifications::AccountSwitchFailed->value, $e);
				}
				if (!$oAccount) {
					throw new ClientException(Notifications::AccountSwitchFailed->value);
				}

				// Test the login
				$oImapClient = new \X2Mail\Mail\Imap\ImapClient;
				$oImapClient->SetLogger($this->Logger());
				$this->imapConnect($oAccount, false, $oImapClient);
			}
			$this->SetAdditionalAuthToken($oAccount);
			return true;
		}
		return false;
	}

	/**
	 * Returns X2Mail\Engine\Model\AdditionalAccount when it exists,
	 * else returns X2Mail\Engine\Model\MainAccount when it exists,
	 * else null
	 *
	 * @throws \X2Mail\Engine\Exceptions\ClientException
	 */
	public function getAccountFromToken(bool $bThrowExceptionOnFalse = true): ?Account
	{
		$this->getMainAccountFromToken($bThrowExceptionOnFalse);

		if (false === $this->oAdditionalAuthAccount && isset($_COOKIE[self::AUTH_ADDITIONAL_TOKEN_KEY])) {
			$aData = Cookies::getSecure(self::AUTH_ADDITIONAL_TOKEN_KEY);
			if ($aData) {
				$this->oAdditionalAuthAccount = AdditionalAccount::NewInstanceFromTokenArray(
					$this,
					$aData,
					$bThrowExceptionOnFalse
				);
			}
			if (!$this->oAdditionalAuthAccount) {
				$this->oAdditionalAuthAccount = null;
				Cookies::clear(self::AUTH_ADDITIONAL_TOKEN_KEY);
			}
		}

		return $this->oAdditionalAuthAccount ?: $this->oMainAuthAccount;
	}

	/**
	 * @throws \X2Mail\Engine\Exceptions\ClientException
	 */
	public function getMainAccountFromToken(bool $bThrowExceptionOnFalse = true): ?MainAccount
	{
		if (false === $this->oMainAuthAccount) try {
			$this->oMainAuthAccount = null;

			$aData = Cookies::getSecure(self::AUTH_SPEC_TOKEN_KEY);
			if ($aData) {
				/**
				 * Server side control/kickout of logged in sessions
				 */
				$sToken = Utils::GetSessionToken(false);
				if (!$sToken) {
//					\X2Mail\Mail\Base\Http::StatusHeader(401);
					if (isset($_COOKIE[Utils::SESSION_TOKEN])) {
						\X2Mail\Engine\Log::notice('TOKENS', 'SESSION_TOKEN invalid');
					} else {
						\X2Mail\Engine\Log::notice('TOKENS', 'SESSION_TOKEN not set');
					}
				} else {
					$oMainAuthAccount = MainAccount::NewInstanceFromTokenArray(
						$this,
						$aData,
						$bThrowExceptionOnFalse
					);
					if ($oMainAuthAccount) {
						$sTokenValue = $this->StorageProvider()->Get($oMainAuthAccount, StorageType::SESSION->value, $sToken);
						if ($sTokenValue) {
							$this->oMainAuthAccount = $oMainAuthAccount;
						} else {
							$this->StorageProvider()->Clear($oMainAuthAccount, StorageType::SESSION->value, $sToken);
							\X2Mail\Engine\Log::notice('TOKENS', 'SESSION_TOKEN value invalid: ' . \get_debug_type($sTokenValue));
						}
					} else {
						\X2Mail\Engine\Log::notice('TOKENS', 'AUTH_SPEC_TOKEN_KEY invalid');
					}
				}
				if (!$this->oMainAuthAccount) {
					Cookies::clear(Utils::SESSION_TOKEN);
//					\X2Mail\Mail\Base\Http::StatusHeader(401);
					$this->Logout(true);
//					$sAdditionalMessage = $this->StaticI18N('SESSION_GONE');
					throw new ClientException(Notifications::InvalidToken->value, null, 'Session gone');
				}
			} else {
				$oAccount = $this->GetAccountFromSignMeToken();
				if ($oAccount) {
					$this->StorageProvider()->Put(
						$oAccount,
						StorageType::SESSION->value,
						Utils::GetSessionToken(),
						'true'
					);
					$this->SetAuthToken($oAccount);
				}
			}

			if (!$this->oMainAuthAccount) {
				throw new ClientException(Notifications::InvalidToken->value, null, 'Account undefined');
			}
		} catch (\Throwable $e) {
			if ($bThrowExceptionOnFalse) {
				throw $e;
			}
		}

		return $this->oMainAuthAccount;
	}

	public function SetMainAuthAccount(MainAccount $oAccount): void
	{
		$this->oAdditionalAuthAccount = false;
		$this->oMainAuthAccount = $oAccount;
	}

	public function SetAuthToken(MainAccount $oAccount): void
	{
		$this->SetMainAuthAccount($oAccount);
		Cookies::setSecure(self::AUTH_SPEC_TOKEN_KEY, $oAccount);
	}

	public function SetAdditionalAuthToken(?AdditionalAccount $oAccount): void
	{
		$this->oAdditionalAuthAccount = $oAccount ?: false;
		Cookies::setSecure(self::AUTH_ADDITIONAL_TOKEN_KEY, $oAccount);
	}

	/**
	 * SignMe methods used for the "remember me" cookie
	 */

	private static function GetSignMeToken(): ?array
	{
		$sSignMeToken = Cookies::get(self::AUTH_SIGN_ME_TOKEN_KEY);
		if ($sSignMeToken) {
			\X2Mail\Engine\Log::notice(self::AUTH_SIGN_ME_TOKEN_KEY, 'decrypt');
			$aResult = \X2Mail\Engine\Crypt::DecryptUrlSafe($sSignMeToken, 'signme');
			if (isset($aResult['e'], $aResult['u']) && \X2Mail\Engine\UUID::isValid($aResult['u'])) {
				if (!isset($aResult['c'])) {
					$aResult['c'] = \array_key_last($aResult);
					$aResult['d'] = \end($aResult);
				}
				return $aResult;
			}
			\X2Mail\Engine\Log::notice(self::AUTH_SIGN_ME_TOKEN_KEY, 'invalid');
			Cookies::clear(self::AUTH_SIGN_ME_TOKEN_KEY);
		}
		return null;
	}

	public function SetSignMeToken(MainAccount $oAccount): void
	{
		$this->ClearSignMeData();
		$uuid = \X2Mail\Engine\UUID::generate();
		$data = \X2Mail\Engine\Crypt::Encrypt($oAccount, 'signme');
		Cookies::set(
			self::AUTH_SIGN_ME_TOKEN_KEY,
			\X2Mail\Engine\Crypt::EncryptUrlSafe([
				'e' => $oAccount->Email(),
				'u' => $uuid,
				'c' => $data[0],
				'd' => \base64_encode($data[1])
			], 'signme'),
			\time() + 3600 * 24 * 30 // 30 days
		);
		$this->StorageProvider()->Put($oAccount, StorageType::SIGN_ME->value, $uuid, $data[2]);
	}

	public function GetAccountFromSignMeToken(): ?MainAccount
	{
		$aTokenData = self::GetSignMeToken();
		if ($aTokenData) {
			try
			{
				$sAuthToken = $this->StorageProvider()->Get(
					$aTokenData['e'],
					StorageType::SIGN_ME->value,
					$aTokenData['u']
				);
				if (!$sAuthToken) {
					throw new \RuntimeException("server token not found for {$aTokenData['e']}/.sign_me/{$aTokenData['u']}");
				}
				$aAccountHash = \X2Mail\Engine\Crypt::Decrypt([
					$aTokenData['c'],
					\base64_decode($aTokenData['d']),
					$sAuthToken
				], 'signme');
				if (!\is_array($aAccountHash)) {
					throw new \RuntimeException('token decrypt failed');
				}
				$oAccount = MainAccount::NewInstanceFromTokenArray($this, $aAccountHash);
				if (!$oAccount) {
					throw new \RuntimeException('token has no account');
				}
				$this->imapConnect($oAccount);
				// Update lifetime
				$this->SetSignMeToken($oAccount);
				return $oAccount;
			}
			catch (\Throwable $oException)
			{
				\X2Mail\Engine\Log::warning(self::AUTH_SIGN_ME_TOKEN_KEY, $oException->getMessage());
				$this->ClearSignMeData();
			}
		}
		return null;
	}

	protected function ClearSignMeData() : void
	{
		$aTokenData = self::GetSignMeToken();
		if ($aTokenData) {
			$this->StorageProvider()->Clear($aTokenData['e'], StorageType::SIGN_ME->value, $aTokenData['u']);
		}
		Cookies::clear(self::AUTH_SIGN_ME_TOKEN_KEY);
	}

	/**
	 * Logout methods
	 */

	public function Logout(bool $bMain) : void
	{
//		Cookies::clear(Utils::SESSION_TOKEN);
		Cookies::clear(self::AUTH_ADDITIONAL_TOKEN_KEY);
		$bMain && Cookies::clear(self::AUTH_SPEC_TOKEN_KEY);
		// TODO: kill SignMe data to prevent automatic login?
	}

	/**
	 * @throws \X2Mail\Engine\Exceptions\ClientException
	 */
	protected function imapConnect(Account $oAccount, bool $bAuthLog = false, ?\X2Mail\Mail\Imap\ImapClient $oImapClient = null): void
	{
		try {
			if (!$oImapClient) {
				$oImapClient = $this->ImapClient();
			}
			$oAccount->ImapConnectAndLogin($this->Plugins(), $oImapClient, $this->Config());
		} catch (ClientException $oException) {
			throw $oException;
		} catch (\X2Mail\Mail\Net\Exceptions\ConnectionException $oException) {
			throw new ClientException(Notifications::ConnectionError->value, $oException);
		} catch (\X2Mail\Mail\Imap\Exceptions\LoginBadCredentialsException $oException) {
			if ($bAuthLog) {
				$this->LoggerAuthHelper($oAccount);
			}

			if ($this->Config()->Get('imap', 'show_login_alert', true)) {
				throw new ClientException(Notifications::AuthError->value, $oException, $oException->getAlertFromStatus());
			} else {
				throw new ClientException(Notifications::AuthError->value, $oException);
			}
		} catch (\Throwable $oException) {
			throw new ClientException(Notifications::AuthError->value, $oException);
		}
	}

}
