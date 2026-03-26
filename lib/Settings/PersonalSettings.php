<?php

declare(strict_types=1);

namespace OCA\X2Mail\Settings;

use OCA\X2Mail\Util\SnappyMailHelper;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class PersonalSettings implements ISettings
{
    public function getForm()
    {
        SnappyMailHelper::loadApp();
        $brandName = \RainLoop\Api::Config()->Get('webmail', 'title', 'X2Mail');

        return new TemplateResponse('x2mail', 'personal_settings', [
            'brandName' => $brandName,
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
