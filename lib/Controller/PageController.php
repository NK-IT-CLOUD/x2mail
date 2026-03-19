<?php

namespace OCA\X2Mail\Controller;

use OCA\X2Mail\Util\SnappyMailHelper;
use OCA\X2Mail\ContentSecurityPolicy;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IRequest;

class PageController extends Controller
{
	public function __construct(string $appName, IRequest $request, private IConfig $config) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index()
	{
		$bAdmin = false;
		$mailtoAddress = '';
		$queryString = $this->request->server['QUERY_STRING'] ?? '';
		if ($queryString !== '') {
			// Handle ?mailto&to=mailto:user@example.com — extract email, render page with compose trigger
			if (\str_starts_with($queryString, 'mailto')) {
				$sTo = \trim($_GET['to'] ?? '');
				if ($sTo && \preg_match('/^mailto:/i', $sTo)) {
					$mailtoAddress = $sTo;
				}
				// Don't call Service::Handle() — render the page directly with mailto param
			} else {
				SnappyMailHelper::loadApp();
				$adminKey = \RainLoop\Api::Config()->Get('security', 'admin_panel_key', 'admin');
				$bAdmin = \hash_equals($adminKey, $queryString);
				if (!$bAdmin) {
					return SnappyMailHelper::startApp(true);
				}
			}
		}

		if (!$bAdmin && $this->config->getAppValue('x2mail', 'snappymail-no-embed')) {
			\OCP\Server::get(\OCP\INavigationManager::class)->setActiveEntry('x2mail');
			\OCP\Util::addScript('x2mail', 'snappymail');
			\OCP\Util::addStyle('x2mail', 'style');
			SnappyMailHelper::startApp();
			$target = $this->request->getParam('target', '');
			$target = \preg_replace('/[^a-zA-Z0-9\/\-_.]/', '', $target);
			$response = new TemplateResponse('x2mail', 'index', [
				'snappymail-iframe-url' => SnappyMailHelper::normalizeUrl(SnappyMailHelper::getAppUrl())
					. ($target === '' ? '' : "#{$target}")
			]);
			$csp = new ContentSecurityPolicy();
			$csp->addAllowedFrameDomain("'self'");
			$response->setContentSecurityPolicy($csp);
			return $response;
		}

		\OCP\Server::get(\OCP\INavigationManager::class)->setActiveEntry('x2mail');

		\OCP\Util::addStyle('x2mail', 'embed');

		SnappyMailHelper::startApp();
		$oConfig = \RainLoop\Api::Config();
		$oActions = $bAdmin ? new \RainLoop\ActionsAdmin() : \RainLoop\Api::Actions();
		$oHttp = \MailSo\Base\Http::SingletonInstance();
		$oServiceActions = new \RainLoop\ServiceActions($oHttp, $oActions);
		$sAppJsMin = $oConfig->Get('debug', 'javascript', false) ? '' : '.min';
		$sAppCssMin = $oConfig->Get('debug', 'css', false) ? '' : '.min';
		$sLanguage = $oActions->GetLanguage(false);

		$csp = new ContentSecurityPolicy();
		$sNonce = $csp->getSnappyMailNonce();

		$params = [
			'Admin' => $bAdmin ? 1 : 0,
			'LoadingDescriptionEsc' => \htmlspecialchars($oConfig->Get('webmail', 'loading_description', 'SnappyMail'), ENT_QUOTES|ENT_IGNORE, 'UTF-8'),
			'BaseTemplates' => \RainLoop\Utils::ClearHtmlOutput($oServiceActions->compileTemplates($bAdmin)),
			'BaseAppBootScript' => \file_get_contents(APP_VERSION_ROOT_PATH.'static/js'.($sAppJsMin ? '/min' : '').'/boot'.$sAppJsMin.'.js'),
			'BaseAppBootScriptNonce' => $sNonce,
			'BaseLanguage' => $oActions->compileLanguage($sLanguage, $bAdmin),
			'BaseAppBootCss' => \file_get_contents(APP_VERSION_ROOT_PATH.'static/css/boot'.$sAppCssMin.'.css'),
			'BaseAppThemeCss' => \preg_replace(
				'/\\s*([:;{},]+)\\s*/s',
				'$1',
				$oActions->compileCss($oActions->GetTheme($bAdmin), $bAdmin)
			)
		];

		\OCP\Util::addHeader('link', ['type'=>'text/css','rel'=>'stylesheet','href'=>\RainLoop\Utils::WebStaticPath('css/'.($bAdmin?'admin':'app').$sAppCssMin.'.css')], '');

		// If mailto address was passed, set the SM cookie directly so AppData picks it up
		if ($mailtoAddress) {
			\SnappyMail\Cookies::set(
				\RainLoop\Actions::AUTH_MAILTO_TOKEN_KEY,
				\RainLoop\Utils::EncodeKeyValuesQ([
					'Time' => \microtime(true),
					'MailTo' => 'MailTo',
					'To' => $mailtoAddress
				])
			);
		}

		$response = new TemplateResponse('x2mail', 'index_embed', $params);

		$response->setContentSecurityPolicy($csp);

		return $response;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function appGet()
	{
		return SnappyMailHelper::startApp(true);
	}

	// NoCSRFRequired: SnappyMail's internal AJAX does not carry Nextcloud CSRF
	// tokens; it uses its own CSRF protection within the SM session.
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function appPost()
	{
		return SnappyMailHelper::startApp(true);
	}

	// NoCSRFRequired: SnappyMail's internal AJAX does not carry Nextcloud CSRF
	// tokens; it uses its own CSRF protection within the SM session.
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function indexPost()
	{
		return SnappyMailHelper::startApp(true);
	}
}
