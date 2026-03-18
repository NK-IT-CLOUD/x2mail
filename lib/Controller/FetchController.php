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

	private ?string $userId;

	public function __construct(string $appName, IRequest $request, IAppManager $appManager, IConfig $config, IL10N $l, ?string $userId) {
		parent::__construct($appName, $request);
		$this->config = $config;
		$this->appManager = $appManager;
		$this->l = $l;
		$this->userId = $userId;
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

			$appname = $this->request->getParam('appname', '');
			if ($appname === 'x2mail') {
				// OIDC auto-login is the primary auth method
				$oidcEnabled = $this->request->getParam('snappymail-autologin-oidc') !== null;
				$this->config->setAppValue('x2mail', 'snappymail-autologin-oidc', $oidcEnabled ? '1' : '0');
				// Auto-login must be on for OIDC to work
				$this->config->setAppValue('x2mail', 'snappymail-autologin', $oidcEnabled ? '1' : '0');
				$this->config->setAppValue('x2mail', 'snappymail-no-embed', $this->request->getParam('snappymail-no-embed') !== null ? '1' : '0');
				// X2Mail debug log
				$this->config->setAppValue('x2mail', 'debug_log', $this->request->getParam('x2mail-debug-log') !== null ? '1' : '0');
			} else {
				return new JSONResponse([
					'status' => 'error',
					'Message' => $this->l->t('Invalid argument(s)')
				]);
			}

			SnappyMailHelper::loadApp();

			$oConfig = \RainLoop\Api::Config();
			$appPath = $this->request->getParam('snappymail-app_path', '');
			if ($appPath !== '') {
				// Validate app_path: must start with / and must not contain protocol
				if (\str_starts_with($appPath, '/') && !\str_contains($appPath, '://')) {
					$oConfig->Set('webmail', 'app_path', $appPath);
				}
			}
			$ncLang = $this->request->getParam('snappymail-nc-lang');
			$oConfig->Set('webmail', 'allow_languages_on_settings', $ncLang === null);
			$oConfig->Set('login', 'allow_languages_on_login', $ncLang === null);
			$oConfig->Save();

			$debug = $this->request->getParam('snappymail-debug') !== null;
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
			$appname = $this->request->getParam('appname', '');
			$password = $this->request->getParam('snappymail-password');
			$email = $this->request->getParam('snappymail-email');
			if ($appname === 'x2mail' && $password !== null && $email !== null) {
				$sUser = $this->userId;

				$sEmail = $email;
				$this->config->setUserValue($sUser, 'x2mail', 'snappymail-email', $sEmail);

				$sPass = $password;
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
