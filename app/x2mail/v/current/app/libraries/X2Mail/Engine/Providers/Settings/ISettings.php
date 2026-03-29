<?php

namespace X2Mail\Engine\Providers\Settings;

interface ISettings
{
	public function Load(\X2Mail\Engine\Model\Account $oAccount) : array;

	public function Save(\X2Mail\Engine\Model\Account $oAccount, \X2Mail\Engine\Settings $oSettings) : bool;

	public function Delete(\X2Mail\Engine\Model\Account $oAccount) : bool;
}
