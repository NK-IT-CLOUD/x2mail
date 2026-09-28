<?php

class StandalonePlugin extends \X2Mail\Engine\Plugins\AbstractPlugin
{
	const
		NAME = 'Standalone',
		VERSION = '1.0.0',
		RELEASE = '2026-09-23',
		CATEGORY = 'Integrations',
		DESCRIPTION = 'X2Mail standalone webmail: OIDC token login without Nextcloud',
		REQUIRED = '2.38.0';

	public function Init() : void
	{
		if (!\class_exists(\X2Mail\Standalone\Runtime::class)) {
			return;
		}
		$this->addHook('imap.before-login', 'beforeLogin');
		$this->addHook('smtp.before-login', 'beforeLogin');
		$this->addHook('sieve.before-login', 'beforeLogin');
		$this->addHook('filter.app-data', 'FilterAppData');
		$this->addHook('filter.application-config', 'FilterApplicationConfig');
		$this->addHook('main.fabrica', 'MainFabrica');
		$this->addJs('js/session-guard.js');
		$this->addJs('js/theme.js');
		$this->addCss('css/nc-theming.css');
	}

	public function Supported() : string
	{
		return \class_exists(\X2Mail\Standalone\Runtime::class) ? '' : 'Only for the X2Mail standalone webmail';
	}

	public function beforeLogin(\X2Mail\Engine\Model\Account $oAccount, \X2Mail\Mail\Net\NetClient $oClient, \X2Mail\Mail\Net\ConnectSettings $oSettings) : void
	{
		(new \X2Mail\Standalone\Engine\TokenInjector())->apply($oAccount, $oSettings, \X2Mail\Standalone\Runtime::identity());
	}

	/**
	 * @param array<string, mixed> $aResult
	 */
	public function FilterAppData(bool $bAdmin, array &$aResult) : void
	{
		if (!$bAdmin && isset($aResult['System']) && \is_array($aResult['System'])) {
			$aResult['System']['customLogoutLink'] = '/oidc/logout';
			$sPrimaryColor = \X2Mail\Standalone\Runtime::primaryColor();
			if (null !== $sPrimaryColor) {
				$aResult['System']['x2wPrimaryColor'] = $sPrimaryColor;
			}
		}
	}

	public function FilterApplicationConfig(\X2Mail\Engine\Config\Application $oConfig) : void
	{
		$oConfig->Set('contacts', 'enable', null !== \X2Mail\Standalone\Runtime::dav());
	}

	public function MainFabrica(string $sName, mixed &$mResult) : void
	{
		if ('address-book' === $sName && null !== \X2Mail\Standalone\Runtime::dav()) {
			$mResult = \X2Mail\Standalone\Dav\AddressBookFactory::fromRuntime(new \X2Mail\Standalone\Log());
		}
	}
}
