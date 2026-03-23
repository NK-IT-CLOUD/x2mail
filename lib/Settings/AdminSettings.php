<?php

namespace OCA\X2Mail\Settings;

use OCA\X2Mail\Util\SnappyMailHelper;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings
{
	public function __construct(
		private IConfig $config,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
		private IAppManager $appManager,
		private IGroupManager $groupManager,
	) {
	}

	public function getForm()
	{
		\OCA\X2Mail\Util\SnappyMailHelper::loadApp();

		$keys = [
			'snappymail-autologin-oidc',
			'snappymail-no-embed',
		];
		$parameters = [];
		foreach ($keys as $k) {
			$v = $this->config->getAppValue('x2mail', $k);
			$parameters[$k] = $v;
		}
		$parameters['x2mail-debug-log'] = $this->config->getAppValue('x2mail', 'debug_log', '0') === '1';
		$user = $this->userSession->getUser();
		$uid = $user ? $user->getUID() : '';
		if ($uid && $this->groupManager->isAdmin($uid)) {
			SnappyMailHelper::loadApp();
			$parameters['snappymail-admin-panel-link'] =
				$this->urlGenerator->linkToRoute('x2mail.page.index')
				. '?' . \RainLoop\Api::Config()->Get('security', 'admin_panel_key', 'admin');
		}

		$oConfig = \RainLoop\Api::Config();
		$passfile = APP_PRIVATE_DATA . 'admin_password.txt';
		$sPassword = '';
		if (\is_file($passfile)) {
			$sPassword = \file_get_contents($passfile) ?: '';
			if (isset($parameters['snappymail-admin-panel-link'])) {
				$parameters['snappymail-admin-panel-link'] .= '#/security';
			}
		}
		$parameters['snappymail-admin-password'] = $sPassword;

		// RainLoop import removed — X2Mail is OIDC-first

		$parameters['snappymail-debug'] = $oConfig->Get('debug', 'enable', false);

		// Check for nextcloud plugin update
		foreach (\SnappyMail\Repository::getPackagesList()['List'] as $plugin) {
			if ('nextcloud' == $plugin['id'] && $plugin['canBeUpdated']) {
				\SnappyMail\Repository::installPackage('plugin', 'nextcloud');
			}
		}

		$app_path = $oConfig->Get('webmail', 'app_path');
		if (!$app_path) {
			$app_path = \preg_replace('#(?<!:)/+#', '/', \rtrim($this->appManager->getAppWebPath('x2mail'), '/') . '/app/');
			$oConfig->Set('webmail', 'app_path', $app_path);
			$oConfig->Set('webmail', 'theme', 'NextcloudV25+');
			$oConfig->Save();
		}
		$parameters['snappymail-app_path'] = $oConfig->Get('webmail', 'app_path', false);
		$parameters['snappymail-nc-lang'] = !$oConfig->Get('webmail', 'allow_languages_on_settings', true);

		\OCP\Util::addScript('x2mail', 'snappymail');
		\OCP\Util::addScript('x2mail', 'setup-wizard');
		\OCP\Util::addStyle('x2mail', 'setup-wizard');
		return new TemplateResponse('x2mail', 'admin-local', $parameters);
	}

	public function getSection()
	{
		return 'x2mail';
	}

	public function getPriority()
	{
		return 50;
	}
}
