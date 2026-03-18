<?php

namespace OCA\X2Mail\Controller;

use OCA\X2Mail\Util\SnappyMailHelper;

use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;

class FetchController extends Controller {
	private IConfig $config;
	private IAppManager $appManager;
	private IL10N $l;

	public function __construct(string $appName, IRequest $request, IAppManager $appManager, IConfig $config, IL10N $l) {
		parent::__construct($appName, $request);
		$this->config = $config;
		$this->appManager = $appManager;
		$this->l = $l;
	}

	public function upgrade(): JSONResponse {
		$error = 'Upgrade failed';
		try {
			SnappyMailHelper::loadApp();
			if (\SnappyMail\Upgrade::core()) {
				return new JSONResponse([
					'status' => 'success',
					'Message' => $this->l->t('Upgraded successfully')
				]);
			}
		} catch (\Exception $e) {
			$error .= ': ' . $e->getMessage();
		}
		return new JSONResponse([
			'status' => 'error',
			'Message' => $error
		]);
	}

	public function setAdmin(): JSONResponse {
		try {
			$sUrl = '';
			$sPath = '';

			if (isset($_POST['appname']) && 'x2mail' === $_POST['appname']) {
				// OIDC auto-login is the primary auth method
				$oidcEnabled = isset($_POST['snappymail-autologin-oidc']);
				$this->config->setAppValue('x2mail', 'snappymail-autologin-oidc', $oidcEnabled ? '1' : '0');
				// Auto-login must be on for OIDC to work
				$this->config->setAppValue('x2mail', 'snappymail-autologin', $oidcEnabled ? '1' : '0');
				$this->config->setAppValue('x2mail', 'snappymail-no-embed', isset($_POST['snappymail-no-embed']) ? '1' : '0');
				// X2Mail debug log
				$this->config->setAppValue('x2mail', 'debug_log', isset($_POST['x2mail-debug-log']) ? '1' : '0');
			} else {
				return new JSONResponse([
					'status' => 'error',
					'Message' => $this->l->t('Invalid argument(s)')
				]);
			}

			SnappyMailHelper::loadApp();

			$oConfig = \RainLoop\Api::Config();
			if (!empty($_POST['snappymail-app_path'])) {
				$oConfig->Set('webmail', 'app_path', $_POST['snappymail-app_path']);
			}
			$oConfig->Set('webmail', 'allow_languages_on_settings', empty($_POST['snappymail-nc-lang']));
			$oConfig->Set('login', 'allow_languages_on_login', empty($_POST['snappymail-nc-lang']));
			$oConfig->Save();

			$debug = !empty($_POST['snappymail-debug']);
			$oConfig = \RainLoop\Api::Config();
			if ($debug != $oConfig->Get('debug', 'enable', false)) {
				$oConfig->Set('debug', 'enable', $debug);
				$oConfig->Save();
			}

			return new JSONResponse([
				'status' => 'success',
				'Message' => $this->l->t('Saved successfully')
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'status' => 'error',
				'Message' => $e->getMessage()
			]);
		}
	}

	#[NoAdminRequired]
	public function setPersonal(): JSONResponse {
		try {
			$sEmail = '';
			if (isset($_POST['appname'], $_POST['snappymail-password'], $_POST['snappymail-email']) && 'x2mail' === $_POST['appname']) {
				$sUser =  \OC::$server->getUserSession()->getUser()->getUID();

				$sEmail = $_POST['snappymail-email'];
				$this->config->setUserValue($sUser, 'x2mail', 'snappymail-email', $sEmail);

				$sPass = $_POST['snappymail-password'];
				if ('******' !== $sPass) {
					$this->config->setUserValue($sUser, 'x2mail', 'passphrase',
						$sPass ? SnappyMailHelper::encodePassword($sPass, \md5($sEmail)) : '');
				}
			} else {
				return new JSONResponse([
					'status' => 'error',
					'Message' => $this->l->t('Invalid argument(s)'),
					'Email' => $sEmail
				]);
			}

			// Logout as the credentials have changed
			SnappyMailHelper::loadApp();
			\RainLoop\Api::Actions()->DoLogout();

			return new JSONResponse([
				'status' => 'success',
				'Message' => $this->l->t('Saved successfully'),
				'Email' => $sEmail
			]);
		} catch (\Exception $e) {
			// Logout as the credentials might have changed, as exception could be in one attribute
			SnappyMailHelper::loadApp();
			\RainLoop\Api::Actions()->DoLogout();

			return new JSONResponse([
				'status' => 'error',
				'Message' => $e->getMessage()
			]);
		}
	}
}
