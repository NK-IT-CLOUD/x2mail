<?php

declare(strict_types=1);

namespace OCA\X2Mail\Settings;

use OCA\X2Mail\Util\EngineHelper;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

class PersonalSettings implements ISettings
{
    public function __construct(
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getForm()
    {
        EngineHelper::loadApp();
        $brandName = \X2Mail\Engine\Api::Config()->Get('webmail', 'title', 'X2Mail');

        return new TemplateResponse('x2mail', 'personal_settings', [
            'brandName' => $brandName,
            'settingsUrl' => $this->urlGenerator->linkToRoute('x2mail.page.index') . '#/settings/accounts',
        ], '');
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
