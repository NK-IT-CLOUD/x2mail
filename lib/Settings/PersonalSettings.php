<?php

declare(strict_types=1);

namespace OCA\X2Mail\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class PersonalSettings implements ISettings
{
	public function getForm()
	{
		return new TemplateResponse('x2mail', 'personal_settings', [], '');
	}

	public function getSection()
	{
		return 'additional';
	}

	public function getPriority()
	{
		return 50;
	}
}
