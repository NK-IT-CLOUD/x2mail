<?php

namespace X2Mail\Engine\Providers\Storage;

use X2Mail\Engine\Providers\Storage\Enumerations\StorageType;

class FileStorage implements \X2Mail\Engine\Providers\Storage\IStorage
{
	use \X2Mail\Mail\Log\Inherit;

	protected string $sDataPath;

	private bool $bLocal;

	public function __construct(string $sStoragePath, bool $bLocal = false)
	{
		$this->sDataPath = \rtrim(\trim($sStoragePath), '\\/');
		$this->bLocal = $bLocal;
	}

	/**
	 * @param \X2Mail\Engine\Model\Account|string|null $mAccount
	 */
	public function Put($mAccount, int $iStorageType, string $sKey, string $sValue) : bool
	{
		$sFileName = $this->generateFileName($mAccount, $iStorageType, $sKey, true);
		try {
			$sFileName && \X2Mail\Engine\Utils::saveFile($sFileName, $sValue);
			return true;
		} catch (\Throwable $e) {
			\X2Mail\Engine\Log::warning('FileStorage', $e->getMessage());
		}
		return false;
	}

	/**
	 * @param \X2Mail\Engine\Model\Account|string|null $mAccount
	 * @param mixed $mDefault = false
	 *
	 * @return mixed
	 */
	public function Get($mAccount, int $iStorageType, string $sKey, $mDefault = false)
	{
		$mValue = false;
		$sFileName = $this->generateFileName($mAccount, $iStorageType, $sKey);
		if ($sFileName && \file_exists($sFileName)) {
			$mValue = \file_get_contents($sFileName);
			// Update mtime to prevent garbage collection
			if (StorageType::SESSION->value === $iStorageType) {
				\touch($sFileName);
			}
		}
		return false === $mValue ? $mDefault : $mValue;
	}

	/**
	 * @param \X2Mail\Engine\Model\Account|string|null $mAccount
	 */
	public function Clear($mAccount, int $iStorageType, string $sKey) : bool
	{
		$sFileName = $this->generateFileName($mAccount, $iStorageType, $sKey);
		return $sFileName && \file_exists($sFileName) && \unlink($sFileName);
	}

	/**
	 * @param \X2Mail\Engine\Model\Account|string $mAccount
	 */
	public function DeleteStorage($mAccount) : bool
	{
		$sPath = $this->generateFileName($mAccount, StorageType::CONFIG->value, '');
		if ($sPath && \is_dir($sPath)) {
			\X2Mail\Mail\Base\Utils::RecRmDir($sPath);
		}
		return true;
	}

	public function IsLocal() : bool
	{
		return $this->bLocal;
	}

	/**
	 * @param \X2Mail\Engine\Model\Account|string|null $mAccount
	 */
	public function GenerateFilePath($mAccount, int $iStorageType, bool $bMkDir = false) : string
	{
		$sEmail = $sSubFolder = $sFilePath = '';
		if (null === $mAccount || StorageType::NOBODY->value === $iStorageType) {
			$sFilePath = $this->sDataPath.'/__nobody__/';
		} else {
			if ($mAccount instanceof \X2Mail\Engine\Model\MainAccount) {
				$sEmail = $mAccount->Email();
			} else if ($mAccount instanceof \X2Mail\Engine\Model\AdditionalAccount) {
				$sEmail = $mAccount->ParentEmail();
				if ($this->bLocal) {
					$sSubFolder = $mAccount->Email();
				}
			} else if (\is_string($mAccount)) {
				$sEmail = $mAccount;
			}

			if ($sEmail) {
				// these are never local
				if (StorageType::SIGN_ME->value === $iStorageType) {
					$sSubFolder = '.sign_me';
				} else if (StorageType::SESSION->value === $iStorageType) {
					$sSubFolder = '.sessions';
				} else if (StorageType::PGP->value === $iStorageType) {
					$sSubFolder = '.pgp';
				} else if (StorageType::ROOT->value === $iStorageType) {
					$sSubFolder = '';
				}
			}

			switch ($iStorageType)
			{
				case StorageType::CONFIG->value:
				case StorageType::SIGN_ME->value:
				case StorageType::SESSION->value:
				case StorageType::PGP->value:
				case StorageType::ROOT->value:
					if (empty($sEmail)) {
						return '';
					}
					if (\is_dir("{$this->sDataPath}/cfg")) {
						\X2Mail\Engine\Upgrade::FileStorage($this->sDataPath);
					}
					$aEmail = \explode('@', $sEmail ?: 'nobody@unknown.tld');
					$sDomain = \trim(1 < \count($aEmail) ? \array_pop($aEmail) : '');
					$sFilePath = $this->sDataPath
						.'/'.\X2Mail\Mail\Base\Utils::SecureFileName($sDomain ?: 'unknown.tld')
						.'/'.\X2Mail\Mail\Base\Utils::SecureFileName(\implode('@', $aEmail) ?: '.unknown')
						.'/'.($sSubFolder ? \X2Mail\Mail\Base\Utils::SecureFileName($sSubFolder).'/' : '');
					break;
				default:
					throw new \Exception("Invalid storage type {$iStorageType}");
			}
		}

		$bMkDir && $sFilePath && \X2Mail\Mail\Base\Utils::mkdir($sFilePath);

		return $sFilePath;
	}

	/**
	 * @param \X2Mail\Engine\Model\Account|string|null $mAccount
	 */
	protected function generateFileName($mAccount, int $iStorageType, string $sKey, bool $bMkDir = false) : string
	{
		$sFilePath = $this->GenerateFilePath($mAccount, $iStorageType, $bMkDir);
		if ($sFilePath) {
			if (StorageType::NOBODY->value === $iStorageType) {
				$sFilePath .= \sha1($sKey ?: \time());
			} else {
				$sFilePath .= ($sKey ? \X2Mail\Mail\Base\Utils::SecureFileName($sKey) : '');
			}
		}
		return $sFilePath;
	}

	public function GC() : void
	{
		\clearstatcache();
		foreach (\glob("{$this->sDataPath}/*", GLOB_ONLYDIR) as $sDomain) {
			foreach (\glob("{$sDomain}/*", GLOB_ONLYDIR) as $sLocal) {
				\X2Mail\Mail\Base\Utils::RecTimeDirRemove("{$sLocal}/.sign_me", 3600 * 24 * 30); // 30 days
				\X2Mail\Mail\Base\Utils::RecTimeDirRemove("{$sLocal}/.sessions", 3600 * 3); // 3 hours
			}
		}
	}
}
