<?php

namespace X2Mail\Engine\Providers\Filters;

interface FiltersInterface
{
	public function Load(\X2Mail\Engine\Model\Account $oAccount) : array;

	public function Save(\X2Mail\Engine\Model\Account $oAccount, string $sScriptName, string $sRaw) : bool;

	public function Activate(\X2Mail\Engine\Model\Account $oAccount, string $sScriptName) : bool;

	public function Delete(\X2Mail\Engine\Model\Account $oAccount, string $sScriptName) : bool;
}
