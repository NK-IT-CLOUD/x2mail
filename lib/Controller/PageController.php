<?php

namespace OCA\X2Mail\Controller;

use OCA\X2Mail\Service\DomainConfigService;
use OCA\X2Mail\Util\EngineHelper;
use OCA\X2Mail\ContentSecurityPolicy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\INavigationManager;
use OCP\IRequest;

class PageController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private INavigationManager $navigationManager,
        private DomainConfigService $domainService,
        private IGroupManager $groupManager,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    /** @return TemplateResponse|void */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(string $target = '')
    {
        // No domain configured → show setup hint instead of useless login form
        if (empty($this->domainService->listDomains())) {
            $isAdmin = $this->userId && $this->groupManager->isAdmin($this->userId);
            return new TemplateResponse('x2mail', 'not_configured', [
                'isAdmin' => $isAdmin,
            ]);
        }

        $bAdmin = false;
        $queryString = $this->request->server['QUERY_STRING'] ?? '';
        if ($queryString !== '') {
            EngineHelper::loadApp();
            $adminKey = \X2Mail\Engine\Api::Config()->Get('security', 'admin_panel_key', 'admin');
            $bAdmin = \hash_equals($adminKey, $queryString);
            if (!$bAdmin) {
                EngineHelper::startApp(true);
                return;
            }
        }

        $this->navigationManager->setActiveEntry('x2mail');

        \OCP\Util::addStyle('x2mail', 'embed');

        EngineHelper::startApp();
        $oConfig = \X2Mail\Engine\Api::Config();
        $oActions = $bAdmin ? new \X2Mail\Engine\ActionsAdmin() : \X2Mail\Engine\Api::Actions();
        $oHttp = \X2Mail\Mail\Base\Http::SingletonInstance();
        $oServiceActions = new \X2Mail\Engine\ServiceActions($oHttp, $oActions);
        $sAppJsMin = $oConfig->Get('debug', 'javascript', false) ? '' : '.min';
        $sAppCssMin = $oConfig->Get('debug', 'css', false) ? '' : '.min';
        $sLanguage = $oActions->GetLanguage(false);

        $csp = new ContentSecurityPolicy();
        $sNonce = $csp->getEngineNonce();

        $params = [
            'Admin' => $bAdmin ? 1 : 0,
            'LoadingDescriptionEsc' => \htmlspecialchars($oConfig->Get('webmail', 'loading_description', 'X2Mail'), ENT_QUOTES | ENT_IGNORE, 'UTF-8'),
            'BaseTemplates' => \X2Mail\Engine\Utils::ClearHtmlOutput($oServiceActions->compileTemplates($bAdmin)),
            'BaseAppBootScript' => \file_get_contents(APP_VERSION_ROOT_PATH . 'static/js' . ($sAppJsMin ? '/min' : '') . '/boot' . $sAppJsMin . '.js'),
            'BaseAppBootScriptNonce' => $sNonce,
            'BaseLanguage' => $oActions->compileLanguage($sLanguage, $bAdmin),
            'BaseAppBootCss' => \file_get_contents(APP_VERSION_ROOT_PATH . 'static/css/boot' . $sAppCssMin . '.css'),
            'BaseAppThemeCss' => \preg_replace(
                '/\\s*([:;{},]+)\\s*/s',
                '$1',
                $oActions->compileCss($oActions->GetTheme($bAdmin), $bAdmin)
            )
        ];

        \OCP\Util::addHeader('link', ['type' => 'text/css','rel' => 'stylesheet','href' => \X2Mail\Engine\Utils::WebStaticPath('css/' . ($bAdmin ? 'admin' : 'app') . $sAppCssMin . '.css')], '');

        $response = new TemplateResponse('x2mail', 'index_embed', $params);

        $response->setContentSecurityPolicy($csp);

        return $response;
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function appGet(): void
    {
        EngineHelper::startApp(true);
    }

    // NoCSRFRequired: the engine's internal AJAX does not carry Nextcloud CSRF
    // tokens; it uses its own CSRF protection within the engine session.
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function appPost(): void
    {
        EngineHelper::startApp(true);
    }

    // NoCSRFRequired: the engine's internal AJAX does not carry Nextcloud CSRF
    // tokens; it uses its own CSRF protection within the engine session.
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function indexPost(): void
    {
        EngineHelper::startApp(true);
    }
}
