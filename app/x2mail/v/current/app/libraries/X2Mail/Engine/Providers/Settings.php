<?php

namespace X2Mail\Engine\Providers;

use X2Mail\Engine\Model\Account;
use X2Mail\Engine\Providers\Settings\ISettings;

class Settings extends \X2Mail\Engine\Providers\AbstractProvider
{
	private ISettings $oDriver;

	public function __construct(ISettings $oDriver)
	{
		$this->oDriver = $oDriver;
	}

	public function Load(Account $oAccount) : \X2Mail\Engine\Settings
	{
		return new \X2Mail\Engine\Settings($this, $oAccount, $this->oDriver->Load($oAccount));
	}

	public function Save(Account $oAccount, \X2Mail\Engine\Settings $oSettings) : bool
	{
		return $this->oDriver->Save($oAccount, $oSettings);
	}

	public function IsActive() : bool
	{
		return true;
	}
}
