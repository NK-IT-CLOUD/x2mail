<?php

namespace X2Mail\Engine;

use X2Mail\Engine\Providers\Storage\Enumerations\StorageType;

abstract class Upgrade
{

	public static function FileStorage(string $sDataPath)
	{
		// /cfg/ex/example@example.com
		foreach (\glob("{$sDataPath}/cfg/*", GLOB_ONLYDIR) as $sOldDir) {
			foreach (\glob("{$sOldDir}/*", GLOB_ONLYDIR) as $sDomainDir) {
				$aEmail = \explode('@', \basename($sDomainDir));
				$sDomain = \trim(1 < \count($aEmail) ? \array_pop($aEmail) : '');
				$sNewDir = $sDataPath
					.'/'.\X2Mail\Mail\Base\Utils::SecureFileName($sDomain ?: 'unknown.tld')
					.'/'.\X2Mail\Mail\Base\Utils::SecureFileName(\implode('@', $aEmail) ?: '.unknown');
//				\X2Mail\Mail\Base\Utils::mkdir($sNewDir)
				if (\is_dir($sNewDir) || \mkdir($sNewDir, 0700, true)) {
					foreach (\glob("{$sDomainDir}/*") as $sItem) {
						$sName = \basename($sItem);
						if ('sign_me' === $sName) {
							// Security issue: remove sign_me files
							\unlink($sItem);
						} else {
							\rename($sItem, "{$sNewDir}/{$sName}");
						}
					}
					\X2Mail\Mail\Base\Utils::RecRmDir($sDomainDir);
				}
			}
		}
		\X2Mail\Mail\Base\Utils::RecRmDir("{$sDataPath}/cfg");
		\X2Mail\Mail\Base\Utils::RecRmDir("{$sDataPath}/data");
		\X2Mail\Mail\Base\Utils::RecRmDir("{$sDataPath}/files");
	}

	/**
	 * Attempt to convert the old less secure data into better secured data
	 */
	public static function ConvertInsecureAccounts(\X2Mail\Engine\Actions $oActions, \X2Mail\Engine\Model\MainAccount $oMainAccount) : array
	{
		$oStorage = $oActions->StorageProvider();
		$sAccounts = $oStorage->Get($oMainAccount, StorageType::CONFIG->value, 'accounts');
		if (!$sAccounts || '{' !== $sAccounts[0]) {
			return [];
		}

		$aAccounts = \json_decode($sAccounts, true);
		if (!$aAccounts || !\is_array($aAccounts)) {
			return [];
		}

		$aNewAccounts = [];
		if (1 < \count($aAccounts)) {
			$sOrder = $oStorage->Get($oMainAccount, StorageType::CONFIG->value, 'accounts_identities_order');
			$aOrder = $sOrder ? \json_decode($sOrder, true) : [];
			if (!empty($aOrder['Accounts']) && \is_array($aOrder['Accounts']) && 1 < \count($aOrder['Accounts'])) {
				$aAccounts = \array_filter(\array_merge(
					\array_fill_keys($aOrder['Accounts'], null),
					$aAccounts
				));
			}
			$sHash = $oMainAccount->CryptKey();
			foreach ($aAccounts as $sEmail => $sToken) {
				if ($oMainAccount->Email() == $sEmail) {
					continue;
				}
				try {
					$aNewAccounts[$sEmail] = [
						'email' => $sEmail,
						'login' => $sEmail,
						'pass' => '',
						'hmac' => \hash_hmac('sha1', '', $sHash)
					];
					if (!$sToken) {
						\X2Mail\Engine\Log::warning('UPGRADE', "ConvertInsecureAccount {$sEmail} no token");
						continue;
					}
					$aAccountHash = self::DecodeKeyValues($sToken);
					if (empty($aAccountHash[0]) || 'token' !== $aAccountHash[0] // simple token validation
						|| 8 > \count($aAccountHash) // length checking
					) {
						\X2Mail\Engine\Log::warning('UPGRADE', "ConvertInsecureAccount {$sEmail} invalid aAccountHash: " . \print_r($aAccountHash,1));
						continue;
					}
					$aAccountHash[3] = Crypt::EncryptUrlSafe($aAccountHash[3], $sHash);
					$aNewAccounts[$sEmail] = [
						'email' => $aAccountHash[1],
						'login' => $aAccountHash[2],
						'pass' => $aAccountHash[3],
						'hmac' => \hash_hmac('sha1', $aAccountHash[3], $sHash)
					];
				} catch (\Throwable $e) {
					\X2Mail\Engine\Log::warning('UPGRADE', "ConvertInsecureAccount {$sEmail} failed");
				}
			}

			$oActions->SetAccounts($oMainAccount, $aNewAccounts);
		}

		$oStorage->Clear($oMainAccount, StorageType::CONFIG->value, 'accounts');

		return $aNewAccounts;
	}

	/**
	 * Decodes old less secure data
	 */
	private static function DecodeKeyValues(string $sData) : array
	{
		$sData = \X2Mail\Mail\Base\Utils::UrlSafeBase64Decode($sData);
		if (!\strlen($sData)) {
			return '';
		}
		$sKey = \md5(APP_SALT);
		$sData = \is_callable('xxtea_decrypt')
			? \xxtea_decrypt($sData, $sKey)
			: \X2Mail\Mail\Base\Xxtea::decrypt($sData, $sKey);
		try {
			return \json_decode($sData, true, 512, JSON_THROW_ON_ERROR) ?: array();
		} catch (\Throwable $e) {
			return \unserialize($sData, ['allowed_classes' => false]) ?: array();
		}
	}

	public static function backup() : string
	{
//		$tar_destination = APP_DATA_FOLDER_PATH . APP_VERSION . '.tar';
		$tar_destination = APP_DATA_FOLDER_PATH . 'backup-' . \date('YmdHis');
		if (\class_exists('PharData')) {
			$tar_destination .= '.tar';
			$tar = new \PharData($tar_destination);
		} else {
			$tar_destination .= '.tgz';
			$tar = new \X2Mail\Engine\Stream\TAR($tar_destination);
		}
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(APP_DATA_FOLDER_PATH . '_data_'),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		$l = \strlen(APP_DATA_FOLDER_PATH);
		foreach ($files as $file) {
			$file = \str_replace('\\', '/', $file);
			if (\is_file($file) && !\strpos($file, '/cache/')) {
				$tar->addFile($file, \substr($file, $l));
			}
		}
		if ($tar instanceof \X2Mail\Engine\Stream\TAR) {
			return $tar_destination;
		}
		$tar->compress(\Phar::GZ);
		\unlink($tar_destination);
		return $tar_destination . '.gz';
	}

	// X2Mail: in-app core update removed — managed via NC app store / CI
	public static function core() : bool
	{
		return false;
	}

	// Prevents Apache access error due to directories being 0700
	public static function fixPermissions($mode = 0755) : void
	{
		\clearstatcache(true);
		\umask(0022);
		$target = \rtrim(APP_INDEX_ROOT_PATH, '\\/');
		// Prevent Apache access error due to directories being 0700
		foreach (\glob("{$target}/x2mail/v/*", \GLOB_ONLYDIR) as $dir) {
			\chmod($dir, 0755);
			foreach (['static','themes'] as $folder) {
				\chmod("{$dir}/{$folder}", 0755);
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator("{$dir}/{$folder}", \FilesystemIterator::SKIP_DOTS),
					\RecursiveIteratorIterator::SELF_FIRST
				);
				foreach ($iterator as $item) {
					if ($item->isDir()) {
						\chmod($item, 0755);
					} else if ($item->isFile()) {
						\chmod($item, 0644);
					}
				}
			}
		}
	}

}
