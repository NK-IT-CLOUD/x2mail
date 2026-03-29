<?php

namespace OCA\X2Mail\Controller;

use OCA\X2Mail\Util\EngineHelper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;

class FetchController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private IAppConfig $appConfig,
        private IL10N $l,
        private EngineHelper $engineHelper,
    ) {
        parent::__construct($appName, $request);
    }

    public function upgrade(): JSONResponse
    {
        $error = 'Upgrade failed';
        try {
            $this->engineHelper->loadApp();
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
                $debugLog = $this->request->getParam('x2mail-debug-log') !== null ? '1' : '0';
                $this->appConfig->setValueString('x2mail', 'debug_log', $debugLog);
            } else {
                return new JSONResponse([
                    'status' => 'error',
                    'Message' => $this->l->t('Invalid argument(s)')
                ]);
            }

            $this->engineHelper->loadApp();

            $oConfig = \X2Mail\Engine\Api::Config();
            $appPath = $this->request->getParam('x2mail-app-path', '');
            if ($appPath !== '') {
                // Validate app_path: must start with / and must not contain protocol
                if (
                    \str_starts_with($appPath, '/')
                    && !\str_contains($appPath, '://')
                    && !\str_contains($appPath, '..')
                ) {
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
}
