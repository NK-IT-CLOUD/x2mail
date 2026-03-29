<?php

namespace X2Mail\Engine\Providers\Domain;

interface DomainInterface
{
	public function Disable(string $sName, bool $bDisable) : bool;

	public function Load(string $sName, bool $bFindWithWildCard = false, bool $bCheckDisabled = true) : ?\X2Mail\Engine\Model\Domain;

	public function Save(\X2Mail\Engine\Model\Domain $oDomain) : bool;

	public function Delete(string $sName) : bool;

	public function GetList(bool $bIncludeAliases = true) : array;
}
