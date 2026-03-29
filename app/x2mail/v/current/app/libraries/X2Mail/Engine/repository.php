<?php

namespace X2Mail\Engine;

abstract class Repository
{
	/**
	 * Check GitHub releases for latest published version (info only, no self-update).
	 * Returns null on any error — caller must handle gracefully.
	 */
	public static function getLatestReleaseVersion() : ?string
	{
		try {
			$oHTTP = HTTP\Request::factory();
			$oHTTP->max_response_kb = 32;
			$oHTTP->timeout = 5;
			$response = $oHTTP->doRequest('GET', 'https://api.github.com/repos/NK-IT-CLOUD/x2mail/releases/latest');
			if ($response && 200 === $response->status) {
				$data = \json_decode($response->body, false, 5);
				if ($data && !empty($data->tag_name)) {
					return \ltrim($data->tag_name, 'v');
				}
			}
		} catch (\Throwable $e) {
			// Network error — silently return null
		}
		return null;
	}

	public static function getEnabledPackagesNames() : array
	{
		return \array_map('trim',
			\explode(',', \strtolower(\X2Mail\Engine\Api::Config()->Get('plugins', 'enabled_list', '')))
		);
	}

	public static function enablePackage(string $sName, bool $bEnable = true) : bool
	{
		if (!\strlen($sName)) {
			return false;
		}

		$oConfig = \X2Mail\Engine\Api::Config();
		$aEnabledPlugins = static::getEnabledPackagesNames();
		$aNewEnabledPlugins = [];

		if ($bEnable) {
			$aNewEnabledPlugins = $aEnabledPlugins;
			$aNewEnabledPlugins[] = $sName;
		} else {
			foreach ($aEnabledPlugins as $sPlugin) {
				if ($sName !== $sPlugin && \strlen($sPlugin)) {
					$aNewEnabledPlugins[] = $sPlugin;
				}
			}
		}

		$oConfig->Set('plugins', 'enabled_list', \trim(\implode(',', \array_unique($aNewEnabledPlugins)), ' ,'));
		return $oConfig->Save();
	}

	/**
	 * Return locally installed plugins.
	 */
	public static function getPackagesList() : array
	{
		empty($_ENV['X2MAIL_INCLUDE_AS_API']) && \X2Mail\Engine\Api::Actions()->IsAdminLoggined();

		$aEnabledPlugins = static::getEnabledPackagesNames();
		$aList = [];

		foreach (\X2Mail\Engine\Api::Actions()->Plugins()->InstalledPlugins() as $aItem) {
			if ($aItem) {
				$aList[] = [
					'type' => 'plugin',
					'id' => $aItem[0],
					'name' => $aItem[2],
					'installed' => $aItem[1],
					'enabled' => \in_array(\strtolower($aItem[0]), $aEnabledPlugins),
					'version' => $aItem[1],
					'file' => '',
					'release' => '',
					'desc' => $aItem[3],
					'canBeDeleted' => false,
					'canBeUpdated' => false
				];
			}
		}

		return [
			'Real' => false,
			'List' => $aList,
			'Error' => ''
		];
	}

	public static function deletePackage(string $sId) : bool
	{
		\X2Mail\Engine\Api::Actions()->IsAdminLoggined();
		static::enablePackage($sId, false);
		$sPath = APP_PLUGINS_PATH . $sId;
		return (!\is_dir($sPath) || \X2Mail\Mail\Base\Utils::RecRmDir($sPath))
			&& (!\is_file("{$sPath}.phar") || \unlink("{$sPath}.phar"));
	}

	public static function installPackage(string $sType, string $sId, string $sFile = '') : bool
	{
		// Only local plugin enable/disable — no remote install
		return false;
	}
}
