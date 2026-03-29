<?php

use X2Mail\Engine\Providers\Storage\Enumerations\StorageType;

class NextcloudStorage extends \X2Mail\Engine\Providers\Storage\FileStorage
{
	/**
	 * @param \X2Mail\Engine\Model\Account|string|null $mAccount
	 */
	public function GenerateFilePath($mAccount, int $iStorageType, bool $bMkDir = false) : string
	{
		$sDataPath = parent::GenerateFilePath($mAccount, $iStorageType, $bMkDir);
		if (StorageType::CONFIG === $iStorageType) {
			$sUID = \OC::$server->getUserSession()->getUser()->getUID();
			$sDataPath .= ".config/{$sUID}/";
			if ($bMkDir && !\is_dir($sDataPath) && !\mkdir($sDataPath, 0700, true)) {
				throw new \X2Mail\Engine\Exceptions\Exception('Can\'t make storage directory "'.$sDataPath.'"');
			}
		}
		return $sDataPath;
	}
}
