<?php

namespace OCA\X2Mail\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

class PersonalSettings implements ISettings
{
	private $config;

	public function __construct(IConfig $config)
	{
		$this->config = $config;
	}

	public function getForm()
	{
		$uid = \OC::$server->getUserSession()->getUser()->getUID();
		$sEmail = $this->config->getUserValue($uid, 'x2mail', 'snappymail-email');
		if ($sPass = $this->config->getUserValue($uid, 'x2mail', 'snappymail-password')) {
			$this->config->deleteUserValue($uid, 'x2mail', 'snappymail-password');
			$this->config->setUserValue($uid, 'x2mail', 'passphrase', $sPass);
		}
		$parameters = [
			'snappymail-email' => $sEmail,
			'snappymail-password' => $this->config->getUserValue($uid, 'x2mail', 'passphrase') ? '******' : ''
		];
		\OCP\Util::addScript('x2mail', 'snappymail');
		return new TemplateResponse('x2mail', 'personal_settings', $parameters, '');
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
