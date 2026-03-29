<?php

namespace OCA\X2Mail\Settings;

use OCA\X2Mail\Util\EngineHelper;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings
{
    public function __construct(
        private IAppConfig $appConfig,
        private IUserSession $userSession,
        private IURLGenerator $urlGenerator,
        private IAppManager $appManager,
        private IGroupManager $groupManager,
        private EngineHelper $engineHelper,
    ) {
    }

    public function getForm()
    {
        $this->engineHelper->loadApp();

        $keys = [
            'autologin-oidc',
        ];
        $parameters = [];
        foreach ($keys as $k) {
            $v = $this->appConfig->getValueString('x2mail', $k);
            $parameters['x2mail-' . $k] = $v;
        }
        $parameters['x2mail-debug-log'] = $this->appConfig->getValueString('x2mail', 'debug_log', '0') === '1';
        $user = $this->userSession->getUser();
        $uid = $user ? $user->getUID() : '';
        if ($uid && $this->groupManager->isAdmin($uid)) {
            $this->engineHelper->loadApp();
            $parameters['x2mail-admin-panel-link'] =
                $this->urlGenerator->linkToRoute('x2mail.page.index')
                . '?' . \X2Mail\Engine\Api::Config()->Get('security', 'admin_panel_key', 'admin');
        }

        $oConfig = \X2Mail\Engine\Api::Config();

        // X2Mail is OIDC-first, no legacy import

        $parameters['x2mail-debug'] = $oConfig->Get('debug', 'enable', false);

        // Check for nextcloud plugin update
        foreach (\X2Mail\Engine\Repository::getPackagesList()['List'] as $plugin) {
            if ('nextcloud' == $plugin['id'] && $plugin['canBeUpdated']) {
                \X2Mail\Engine\Repository::installPackage('plugin', 'nextcloud');
            }
        }

        $app_path = $oConfig->Get('webmail', 'app_path');
        if (!$app_path) {
            $webPath = $this->appManager->getAppWebPath('x2mail');
            $app_path = \preg_replace(
                '#(?<!:)/+#',
                '/',
                \rtrim($webPath, '/') . '/app/'
            );
            $oConfig->Set('webmail', 'app_path', $app_path);
            $oConfig->Set('webmail', 'theme', 'x2mail');
            $oConfig->Save();
        }
        $parameters['x2mail-app-path'] = $oConfig->Get('webmail', 'app_path', false);
        $parameters['x2mail-nc-lang'] = !$oConfig->Get('webmail', 'allow_languages_on_settings', true);
        $parameters['x2mail-version'] = $this->appManager->getAppVersion('x2mail');

        \OCP\Util::addScript('x2mail', 'x2mail');
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
