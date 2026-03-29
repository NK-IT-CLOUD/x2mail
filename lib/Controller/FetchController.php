<?php

namespace OCA\X2Mail\Controller;

use OCA\X2Mail\Util\EngineHelper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;

class FetchController extends Controller
{
    private IAppConfig $appConfig;
    private IUserConfig $userConfig;
    private IL10N $l;

    private ?string $userId;

    public function __construct(string $appName, IRequest $request, IAppConfig $appConfig, IUserConfig $userConfig, IL10N $l, ?string $userId)
    {
        parent::__construct($appName, $request);
        $this->appConfig = $appConfig;
        $this->userConfig = $userConfig;
        $this->l = $l;
        $this->userId = $userId;
    }

    public function upgrade(): JSONResponse
    {
        $error = 'Upgrade failed';
        try {
            EngineHelper::loadApp();
            if (\X2Mail\Engine\Upgrade::core()) {
                return new JSONResponse([
                    'status' => 'success',
                    'Message' => $this->l->t('Upgraded successfully')
                ]);
            }
        } catch (\Exception $e) {
            // Don't leak exception details to browser
        }
        return new JSONResponse([
            'status' => 'error',
            'Message' => $error
        ]);
    }

    public function setAdmin(string $appname = ''): JSONResponse
    {
        try {
            if ($appname === 'x2mail') {
                // OIDC auto-login is the primary auth method
                $oidcEnabled = $this->request->getParam('x2mail-autologin-oidc') !== null;
                $this->appConfig->setValueString('x2mail', 'autologin-oidc', $oidcEnabled ? '1' : '0');
                // Auto-login must be on for OIDC to work
                $this->appConfig->setValueString('x2mail', 'autologin', $oidcEnabled ? '1' : '0');
                // X2Mail debug log
                $this->appConfig->setValueString('x2mail', 'debug_log', $this->request->getParam('x2mail-debug-log') !== null ? '1' : '0');
            } else {
                return new JSONResponse([
                    'status' => 'error',
                    'Message' => $this->l->t('Invalid argument(s)')
                ]);
            }

            EngineHelper::loadApp();

            $oConfig = \X2Mail\Engine\Api::Config();
            $appPath = $this->request->getParam('x2mail-app-path', '');
            if ($appPath !== '') {
                // Validate app_path: must start with / and must not contain protocol
                if (\str_starts_with($appPath, '/') && !\str_contains($appPath, '://') && !\str_contains($appPath, '..')) {
                    $oConfig->Set('webmail', 'app_path', \preg_replace('#/+#', '/', $appPath));
                }
            }
            $ncLang = $this->request->getParam('x2mail-nc-lang');
            $oConfig->Set('webmail', 'allow_languages_on_settings', $ncLang === null);
            $oConfig->Set('login', 'allow_languages_on_login', $ncLang === null);
            $oConfig->Save();

            $debug = $this->request->getParam('x2mail-debug') !== null;
            $oConfig = \X2Mail\Engine\Api::Config();
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
                'Message' => 'Save failed'
            ]);
        }
    }

    #[NoAdminRequired]
    public function setPersonal(string $appname = ''): JSONResponse
    {
        try {
            $sEmail = '';
            $password = $this->request->getParam('x2mail-password');
            $email = $this->request->getParam('x2mail-email');
            if ($appname === 'x2mail' && $password !== null && $email !== null) {
                if ($email !== '' && !\filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return new JSONResponse([
                        'status' => 'error',
                        'Message' => $this->l->t('Invalid email address'),
                        'Email' => ''
                    ]);
                }

                $sUser = $this->userId;
                if ($sUser === null) {
                    return new JSONResponse(['status' => 'error', 'Message' => 'Not authenticated'], 401);
                }

                $sEmail = $email;
                $this->userConfig->setValueString($sUser, 'x2mail', 'email', $sEmail);

                $sPass = $password;
                if ('******' !== $sPass) {
                    $this->userConfig->setValueString(
                        $sUser,
                        'x2mail',
                        'passphrase',
                        $sPass ? EngineHelper::encodePassword($sPass, \md5($sEmail)) : ''
                    );
                }
            } else {
                return new JSONResponse([
                    'status' => 'error',
                    'Message' => $this->l->t('Invalid argument(s)'),
                    'Email' => $sEmail
                ]);
            }

            // Logout as the credentials have changed
            EngineHelper::loadApp();
            \X2Mail\Engine\Api::Actions()->DoLogout();

            return new JSONResponse([
                'status' => 'success',
                'Message' => $this->l->t('Saved successfully'),
                'Email' => $sEmail
            ]);
        } catch (\Exception $e) {
            // Logout as the credentials might have changed, as exception could be in one attribute
            EngineHelper::loadApp();
            \X2Mail\Engine\Api::Actions()->DoLogout();

            return new JSONResponse([
                'status' => 'error',
                'Message' => $e->getMessage()
            ]);
        }
    }
}
