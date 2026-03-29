<?php

namespace X2Mail\Engine\Model;

use X2Mail\Engine\Utils;
use X2Mail\Engine\Exceptions\ClientException;

class AdditionalAccount extends Account
{
	public function ParentEmail() : string
	{
		return \X2Mail\Engine\IDN::emailToAscii(\X2Mail\Engine\Api::Actions()->getMainAccountFromToken()->Email());
	}

	public function Hash() : string
	{
		return \sha1(parent::Hash() . $this->ParentEmail());
	}

	public static function convertArray(array $aAccount) : array
	{
		$aResult = parent::convertArray($aAccount);
		$iCount = \count($aAccount);
		if ($aResult && 7 < $iCount && 9 >= $iCount) {
			$aResult['hmac'] = \array_pop($aAccount);
		}
		return $aResult;
	}

	public function asTokenArray(MainAccount $oMainAccount) : array
	{
		$sHash = $oMainAccount->CryptKey();
		$aData = $this->jsonSerialize();
		$aData['pass'] = \X2Mail\Engine\Crypt::EncryptUrlSafe($aData['pass'], $sHash); // sPassword
		if (!empty($aData['smtp']['pass'])) {
			$aData['smtp']['pass'] = \X2Mail\Engine\Crypt::EncryptUrlSafe($aData['smtp']['pass'], $sHash);
		}
		$aData['hmac'] = \hash_hmac('sha1', $aData['pass'], $sHash);
		return $aData;
	}

	public static function NewInstanceFromTokenArray(
		\X2Mail\Engine\Actions $oActions,
		array $aAccountHash,
		bool $bThrowExceptionOnFalse = false) : ?Account /* PHP7.4: ?self*/
	{
		$aAccountHash = static::convertArray($aAccountHash);
		if (!empty($aAccountHash['email'])) {
			$sHash = $oActions->getMainAccountFromToken()->CryptKey();
			// hmac only set when asTokenArray() was used
			$sPasswordHMAC = $aAccountHash['hmac'] ?? null;
			if ($sPasswordHMAC) {
				if ($sPasswordHMAC === \hash_hmac('sha1', $aAccountHash['pass'], $sHash)) {
					$aAccountHash['pass'] = \X2Mail\Engine\Crypt::DecryptUrlSafe($aAccountHash['pass'], $sHash);
					if (!empty($aAccountHash['smtp']['pass'])) {
						$aAccountHash['smtp']['pass'] = \X2Mail\Engine\Crypt::DecryptUrlSafe($aAccountHash['smtp']['pass'], $sHash);
					}
				} else {
					$aAccountHash['pass'] = '';
					if (!empty($aAccountHash['smtp']['pass'])) {
						$aAccountHash['smtp']['pass'] = '';
					}
				}
			}
			return parent::NewInstanceFromTokenArray($oActions, $aAccountHash, $bThrowExceptionOnFalse);
		}
		return null;
	}

}
