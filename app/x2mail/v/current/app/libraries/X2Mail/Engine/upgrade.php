<?php

namespace X2Mail\Engine;

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
