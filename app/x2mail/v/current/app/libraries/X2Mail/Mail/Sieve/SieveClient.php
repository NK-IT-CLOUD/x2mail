<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2014 Usenko Timur
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace X2Mail\Mail\Sieve;

use X2Mail\Mail\Net\Enumerations\ConnectionSecurityType;

/**
 * @category MailSo
 * @package Sieve
 */
class SieveClient extends \X2Mail\Mail\Net\NetClient
{
	private bool $bIsLoggined = false;

	private array $aCapa = array();

	private array $aAuth = array();

	private array $aModules = array();

	public function hasCapability(string $sCapa) : bool
	{
		return isset($this->aCapa[\strtoupper($sCapa)]);
	}

	public function Modules() : array
	{
		return $this->aModules;
	}

	/**
	 * @throws \InvalidArgumentException
	 * @throws \X2Mail\Mail\RuntimeException
	 * @throws \X2Mail\Mail\Net\Exceptions\*
	 * @throws \X2Mail\Mail\Sieve\Exceptions\*
	 */
	public function Connect(\X2Mail\Mail\Net\ConnectSettings $oSettings) : void
	{
		parent::Connect($oSettings);

		$aResponse = $this->parseResponse();
		$this->parseStartupResponse($aResponse);

		if (ConnectionSecurityType::STARTTLS === $this->Settings->type
		 || (ConnectionSecurityType::AUTO_DETECT === $this->Settings->type && $this->hasCapability('STARTTLS'))) {
			$this->StartTLS();
		}
	}

	private function StartTLS() : void
	{
		if ($this->hasCapability('STARTTLS')) {
			$this->sendRequestWithCheck('STARTTLS');
			$this->EnableCrypto();
			$aResponse = $this->parseResponse();
			$this->parseStartupResponse($aResponse);
		} else {
			$this->writeLogException(
				new \X2Mail\Mail\Net\Exceptions\SocketUnsuppoterdSecureConnectionException('STARTTLS is not supported'),
				\LOG_ERR);
		}
	}

	public function supportsAuthType(string $sasl_type) : bool
	{
		return \in_array(\strtoupper($sasl_type), $this->aAuth);
	}

	/**
	 * @throws \InvalidArgumentException
	 * @throws \X2Mail\Mail\RuntimeException
	 * @throws \X2Mail\Mail\Net\Exceptions\*
	 * @throws \X2Mail\Mail\Sieve\Exceptions\*
	 */
	public function Login(Settings $oSettings) : self
	{
		if ($this->bIsLoggined) {
			return $this;
		}

		$sLogin = $oSettings->username;
		$sPassword = $oSettings->passphrase;
		$sLoginAuthKey = '';
		if (!\strlen($sLogin) || !\strlen($sPassword)) {
			$this->writeLogException(new \InvalidArgumentException, \LOG_ERR);
		}

		$type = '';
		if ($this->hasCapability('SASL')) {
			foreach ($oSettings->SASLMechanisms as $sasl_type) {
				if (\in_array(\strtoupper($sasl_type), $this->aAuth) && \X2Mail\Engine\SASL::isSupported($sasl_type)) {
					$type = $sasl_type;
					break;
				}
			}
		}
		if (!$type) {
			if (!$this->Encrypted() && $this->hasCapability('STARTTLS')) {
				$this->StartTLS();
				return $this->Login($oSettings);
			}
//			$this->writeLogException(new \UnexpectedValueException('No supported SASL mechanism found'), \LOG_ERR);
			$this->writeLogException(new \X2Mail\Mail\Sieve\Exceptions\LoginException, \LOG_ERR);
		}

		$SASL = \X2Mail\Engine\SASL::factory($type);

		$bAuth = false;
		try
		{
			if (\str_starts_with($type, 'SCRAM-'))
			{
/*
				$sAuthzid = $this->getResponseValue($this->SendRequestGetResponse('AUTHENTICATE', array($type)), \X2Mail\Mail\Imap\Enumerations\ResponseType::CONTINUATION);
				$this->sendRaw($SASL->authenticate($sLogin, $sPassword/*, $sAuthzid* /), true);
				$sChallenge = $SASL->challenge($this->getResponseValue($this->getResponse(), \X2Mail\Mail\Imap\Enumerations\ResponseType::CONTINUATION));
				$this->logMask($sChallenge);
				$this->sendRaw($sChallenge);
				$oResponse = $this->getResponse();
				$SASL->verify($this->getResponseValue($oResponse));
*/
			}
			else if ('PLAIN' === $type || 'OAUTHBEARER' === $type || 'XOAUTH2' === $type)
			{
				$sAuth = $SASL->authenticate($sLogin, $sPassword, $sLoginAuthKey);
				$this->logMask($sAuth);

				if ($oSettings->authLiteral) {
					$this->sendRaw("AUTHENTICATE \"{$type}\" {".\strlen($sAuth).'+}');
					$this->sendRaw($sAuth);
				} else {
					$this->sendRaw("AUTHENTICATE \"{$type}\" \"{$sAuth}\"");
				}

				$aResponse = $this->parseResponse();
				$this->parseStartupResponse($aResponse);
				$bAuth = true;
			}
			else if ('LOGIN' === $type)
			{
				$sLogin = $SASL->authenticate($sLogin, $sPassword);
				$sPassword = $SASL->challenge('');
				$this->logMask($sPassword);

				$this->sendRaw('AUTHENTICATE "LOGIN"');
				$this->sendRaw('{'.\strlen($sLogin).'+}');
				$this->sendRaw($sLogin);
				$this->sendRaw('{'.\strlen($sPassword).'+}');
				$this->sendRaw($sPassword);

				$aResponse = $this->parseResponse();
				$this->parseStartupResponse($aResponse);
				$bAuth = true;
			}
		}
		catch (\X2Mail\Mail\Sieve\Exceptions\NegativeResponseException $oException)
		{
			$this->writeLogException(
				new \X2Mail\Mail\Sieve\Exceptions\LoginBadCredentialsException($oException->GetResponses(), '', 0, $oException),
				\LOG_ERR);
		}

		$bAuth || $this->writeLogException(new \X2Mail\Mail\Sieve\Exceptions\LoginBadMethodException, \LOG_ERR);

		$this->bIsLoggined = true;

		return $this;
	}

	/**
	 * @throws \X2Mail\Mail\RuntimeException
	 * @throws \X2Mail\Mail\Net\Exceptions\*
	 * @throws \X2Mail\Mail\Sieve\Exceptions\*
	 */
	public function Logout() : void
	{
		if ($this->bIsLoggined) {
			try {
				$this->sendRequestWithCheck('LOGOUT');
			} catch (\Throwable $e) {
				$this->writeLogException($e, \LOG_WARNING, false);
			}
			$this->bIsLoggined = false;
		}
	}

	/**
	 * @throws \X2Mail\Mail\RuntimeException
	 * @throws \X2Mail\Mail\Net\Exceptions\*
	 * @throws \X2Mail\Mail\Sieve\Exceptions\*
	 */
	public function ListScripts() : array
	{
		$this->sendRequest('LISTSCRIPTS');
		$aResponse = $this->parseResponse();
		$aResult = array();
		foreach ($aResponse as $sLine) {
			$aTokens = $this->parseLine($sLine);
			if ($aTokens) {
				$aResult[$aTokens[0]] = 'ACTIVE' === \substr($sLine, -6);
			}
		}

		return $aResult;
	}

	/**
	 * @throws \X2Mail\Mail\RuntimeException
	 * @throws \X2Mail\Mail\Net\Exceptions\*
	 * @throws \X2Mail\Mail\Sieve\Exceptions\*
	 */
	public function Capability(bool $force = false) : array
	{
		if (!$this->aCapa || $force) {
			$this->sendRequest('CAPABILITY');
			$aResponse = $this->parseResponse();
			$this->parseStartupResponse($aResponse);
		}
		return $this->aCapa;
	}

	/**
	 * @throws \X2Mail\Mail\RuntimeException
	 * @throws \X2Mail\Mail\Net\Exceptions\*
	 * @throws \X2Mail\Mail\Sieve\Exceptions\*
	 */
	public function Noop() : self
	{
		$this->sendRequestWithCheck('NOOP');

		return $this;
	}

	/**
	 * @throws \X2Mail\Mail\RuntimeException
	 * @throws \X2Mail\Mail\Net\Exceptions\*
	 * @throws \X2Mail\Mail\Sieve\Exceptions\*
	 */
	public function GetScript(string $sScriptName) : string
	{
		$sScriptName = \str_replace(["\r", "\n"], '', $sScriptName);
		$sScriptName = \addcslashes($sScriptName, '"\\');
		$this->sendRequest('GETSCRIPT "'.$sScriptName.'"');
		$aResponse = $this->parseResponse();

		$sScript = '';
		if (\count($aResponse)) {
			if ('{' === $aResponse[0][0]) {
				\array_shift($aResponse);
			}

			if (\in_array(\substr($aResponse[\count($aResponse) - 1], 0, 2), array('OK', 'NO'))) {
				\array_pop($aResponse);
			}

			$sScript = \implode("\r\n", $aResponse);
		}

		return $sScript;
	}

	/**
	 * @throws \X2Mail\Mail\RuntimeException
	 * @throws \X2Mail\Mail\Net\Exceptions\*
	 * @throws \X2Mail\Mail\Sieve\Exceptions\*
	 */
	public function PutScript(string $sScriptName, string $sScriptSource) : self
	{
		$sScriptName = \str_replace(["\r", "\n"], '', $sScriptName);
		$sScriptName = \addcslashes($sScriptName, '"\\');
		$sScriptSource = \preg_replace('/\r?\n/', "\r\n", $sScriptSource);
		$this->sendRequest('PUTSCRIPT "'.$sScriptName.'" {'.\strlen($sScriptSource).'+}');
		$this->sendRequestWithCheck($sScriptSource);

		return $this;
	}

	/**
	 * @throws \X2Mail\Mail\RuntimeException
	 * @throws \X2Mail\Mail\Net\Exceptions\*
	 * @throws \X2Mail\Mail\Sieve\Exceptions\*
	 */
	public function CheckScript(string $sScriptSource) : self
	{
		$sScriptSource = \preg_replace('/\r?\n/', "\r\n", $sScriptSource);
		$this->sendRequest('CHECKSCRIPT {'.\strlen($sScriptSource).'+}');
		$this->sendRequestWithCheck($sScriptSource);

		return $this;
	}

	/**
	 * @throws \X2Mail\Mail\RuntimeException
	 * @throws \X2Mail\Mail\Net\Exceptions\*
	 * @throws \X2Mail\Mail\Sieve\Exceptions\*
	 */
	public function SetActiveScript(string $sScriptName) : self
	{
		$sScriptName = \str_replace(["\r", "\n"], '', $sScriptName);
		$sScriptName = \addcslashes($sScriptName, '"\\');
		$this->sendRequestWithCheck('SETACTIVE "'.$sScriptName.'"');

		return $this;
	}

	/**
	 * @throws \X2Mail\Mail\RuntimeException
	 * @throws \X2Mail\Mail\Net\Exceptions\*
	 * @throws \X2Mail\Mail\Sieve\Exceptions\*
	 */
	public function DeleteScript(string $sScriptName) : self
	{
		$sScriptName = \str_replace(["\r", "\n"], '', $sScriptName);
		$sScriptName = \addcslashes($sScriptName, '"\\');
		$this->sendRequestWithCheck('DELETESCRIPT "'.$sScriptName.'"');

		return $this;
	}

	/**
	 * @throws \X2Mail\Mail\RuntimeException
	 * @throws \X2Mail\Mail\Net\Exceptions\*
	 * @throws \X2Mail\Mail\Sieve\Exceptions\*
	 */
	public function RenameScript(string $sOldName, string $sNewName) : self
	{
		$sOldName = \str_replace(["\r", "\n"], '', $sOldName);
		$sNewName = \str_replace(["\r", "\n"], '', $sNewName);
		$sOldName = \addcslashes($sOldName, '"\\');
		$sNewName = \addcslashes($sNewName, '"\\');
		$this->sendRequestWithCheck('RENAMESCRIPT "'.$sOldName.'" "'.$sNewName.'"');

		return $this;
	}

	private function parseLine(string $sLine) : ?array
	{
		if (!\in_array(\substr($sLine, 0, 2), array('OK', 'NO'))) {
			$aResult = array();
			if (\preg_match_all('/(?:(?:"((?:\\\\"|[^"])*)"))/', $sLine, $aResult)) {
				return \array_map('stripcslashes', $aResult[1]);
			}
		}
		return null;
	}

	/**
	 * @throws \InvalidArgumentException
	 * @throws \X2Mail\Mail\RuntimeException
	 * @throws \X2Mail\Mail\Net\Exceptions\*
	 */
	private function parseStartupResponse(array $aResponse) : void
	{
		$this->aCapa = [];
		foreach ($aResponse as $sLine) {
			$aTokens = $this->parseLine($sLine);
			if (empty($aTokens[0]) || \in_array(\substr($sLine, 0, 2), array('OK', 'NO'))) {
				continue;
			}

			$sToken = \strtoupper($aTokens[0]);
			$this->aCapa[$sToken] = isset($aTokens[1]) ? $aTokens[1] : '';

			if (isset($aTokens[1])) {
				switch ($sToken) {
					case 'SASL':
						$this->aAuth = \explode(' ', \strtoupper($aTokens[1]));
						break;
					case 'SIEVE':
						$this->aModules = \explode(' ', \strtolower($aTokens[1]));
						break;
				}
			}
		}
	}

	/**
	 * @throws \InvalidArgumentException
	 * @throws \X2Mail\Mail\RuntimeException
	 * @throws \X2Mail\Mail\Net\Exceptions\*
	 */
	private function sendRequest(string $sRequest) : void
	{
		if (!\strlen(\trim($sRequest))) {
			$this->writeLogException(new \InvalidArgumentException, \LOG_ERR);
		}

		$this->IsConnected(true);

		$this->sendRaw($sRequest);
	}

	/**
	 * @throws \X2Mail\Mail\RuntimeException
	 * @throws \X2Mail\Mail\Net\Exceptions\*
	 * @throws \X2Mail\Mail\Sieve\Exceptions\*
	 */
	private function sendRequestWithCheck(string $sRequest) : void
	{
		$this->sendRequest($sRequest);
		$this->parseResponse();
	}

	private function parseResponse() : array
	{
		$aResult = array();
		while (true) {
			$sResponseBuffer = $this->getNextBuffer();
			if (null === $sResponseBuffer) {
				break;
			}
			// \X2Mail\Mail\Imap\Enumerations\ResponseStatus
			$bEnd = \in_array(\substr($sResponseBuffer, 0, 2), array('OK', 'NO'));
			// convertEndOfLine
			$sLine = \trim($sResponseBuffer);
			if ('}' === \substr($sLine, -1)) {
				$iPos = \strrpos($sLine, '{');
				if (false !== $iPos) {
					$iLen = \intval(\substr($sLine, $iPos + 1, -1));
					if (0 < $iLen) {
						$sResponseBuffer = $this->getNextBuffer($iLen);
						if (\strlen($sResponseBuffer) === $iLen) {
							$sLine = \trim(\substr_replace($sLine, $sResponseBuffer, $iPos));
						}
					}
				}
			}
			$aResult[] = $sLine;
			if ($bEnd) {
				break;
			}
		}

		if (!$aResult || 'OK' !== \substr($aResult[\array_key_last($aResult)], 0, 2)) {
			$this->writeLogException(new \X2Mail\Mail\Sieve\Exceptions\NegativeResponseException($aResult), \LOG_WARNING);
		}

		return $aResult;
	}

	public function getLogName() : string
	{
		return 'SIEVE';
	}

}
