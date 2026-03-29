<?php

namespace X2Mail\Engine\Model;

use X2Mail\Engine\Utils;
use X2Mail\Engine\Exceptions\ClientException;
use X2Mail\Engine\Notifications;
use X2Mail\Engine\Providers\Storage\Enumerations\StorageType;
use X2Mail\Engine\SensitiveString;

class MainAccount extends Account
{
	private ?SensitiveString $sCryptKey = null;

	public function resealCryptKey(SensitiveString $oOldPass) : bool
	{
		$oStorage = \X2Mail\Engine\Api::Actions()->StorageProvider();
		$sKey = $oStorage->Get($this, StorageType::ROOT->value, '.cryptkey');
		if ($sKey) {
			$sKey = \X2Mail\Engine\Crypt::DecryptFromJSON($sKey, $oOldPass);
			if (!$sKey) {
				throw new ClientException(Notifications::CryptKeyError->value);
			}
			$sPass = \X2Mail\Engine\Api::Config()->Get('security', 'insecure_cryptkey', false)
				? $this->Email()
				: $this->ImapPass();
			$sKey = \X2Mail\Engine\Crypt::EncryptToJSON($sKey, $sPass);
			if ($sKey) {
				$this->sCryptKey = null;
				if (\X2Mail\Engine\Api::Actions()->StorageProvider()->Put($this, StorageType::ROOT->value, '.cryptkey', $sKey)) {
					return true;
				}
			}
		}
		return false;
	}

	public function CryptKey() : string
	{
		if (!$this->sCryptKey) {
			// Seal the cryptkey so that people who change their login password
			// can use the old password to re-seal the cryptkey
			$oStorage = \X2Mail\Engine\Api::Actions()->StorageProvider();
			$sKey = $oStorage->Get($this, StorageType::ROOT->value, '.cryptkey');
			$sPass = \X2Mail\Engine\Api::Config()->Get('security', 'insecure_cryptkey', false)
				? $this->Email()
				: $this->ImapPass();
			if (!$sKey) {
				$sKey = \X2Mail\Engine\Crypt::EncryptToJSON(
					\sha1($this->ImapPass() . APP_SALT),
					$sPass
				);
				$oStorage->Put($this, StorageType::ROOT->value, '.cryptkey', $sKey);
			}
			$sKey = \X2Mail\Engine\Crypt::DecryptFromJSON($sKey, $sPass);
			if (!$sKey) {
				throw new ClientException(Notifications::CryptKeyError->value);
			}
			$this->sCryptKey = new SensitiveString(\hex2bin($sKey));
		}
		return $this->sCryptKey;
	}

/*
	// Stores settings in MainAccount
	public function settings() : \X2Mail\Engine\Settings
	{
		return \X2Mail\Engine\Api::Actions()->SettingsProvider()->Load($this);
	}
*/
}
