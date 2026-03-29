<?php

namespace X2Mail\Engine\Providers;

use X2Mail\Engine\Notifications;
use X2Mail\Engine\Exceptions\ClientException;

class Domain extends AbstractProvider
{
	private Domain\DomainInterface $oDriver;

	private \X2Mail\Engine\Plugins\Manager $oPlugins;

	public function __construct(Domain\DomainInterface $oDriver, \X2Mail\Engine\Plugins\Manager $oPlugins)
	{
		$this->oDriver = $oDriver;
		$this->oPlugins = $oPlugins;
	}

	public function Load(string $sName, bool $bFindWithWildCard = false, bool $bCheckDisabled = true, bool $bCheckAliases = true) : ?\X2Mail\Engine\Model\Domain
	{
		$oDomain = $this->oDriver->Load($sName, $bFindWithWildCard, $bCheckDisabled, $bCheckAliases);
		$oDomain && $this->oPlugins->RunHook('filter.domain', array($oDomain));
		return $oDomain;
	}

	public function Save(\X2Mail\Engine\Model\Domain $oDomain) : bool
	{
		return $this->oDriver->Save($oDomain);
	}

	public function SaveAlias(string $sName, string $sAlias) : bool
	{
		if ($this->Load($sName, false, false)) {
			throw new ClientException(\X2Mail\Engine\Notifications::DomainAlreadyExists->value);
		}
		return $this->oDriver->SaveAlias($sName, $sAlias);
	}

	public function Delete(string $sName) : bool
	{
		return $this->oDriver->Delete($sName);
	}

	public function Disable(string $sName, bool $bDisabled) : bool
	{
		return $this->oDriver->Disable($sName, $bDisabled);
	}

	public function GetList(bool $bIncludeAliases = true) : array
	{
		return $this->oDriver->GetList($bIncludeAliases);
	}

	public function LoadOrCreateNewFromAction(\X2Mail\Engine\Actions $oActions, ?string $sNameForTest = null) : ?\X2Mail\Engine\Model\Domain
	{
		$sName = \mb_strtolower((string) $oActions->GetActionParam('name', ''));
		if (\strlen($sName) && $sNameForTest && !\str_contains($sName, '*')) {
			$sNameForTest = null;
		}
		if (\strlen($sName) || $sNameForTest) {
			if (!$sNameForTest && !empty($oActions->GetActionParam('create', 0)) && $this->Load($sName)) {
				throw new ClientException(\X2Mail\Engine\Notifications::DomainAlreadyExists->value);
			}
			return \X2Mail\Engine\Model\Domain::fromArray($sNameForTest ?: $sName, [
				'IMAP' => $oActions->GetActionParam('IMAP'),
				'SMTP' => $oActions->GetActionParam('SMTP'),
				'Sieve' => $oActions->GetActionParam('Sieve'),
				'whiteList' => $oActions->GetActionParam('whiteList')
			]);
		}
		return null;
	}

	public function IsActive() : bool
	{
		return $this->oDriver instanceof Domain\DomainInterface;
	}

	public function getByEmailAddress(string $sEmail) : \X2Mail\Engine\Model\Domain
	{
		$oDomain = $this->Load(\X2Mail\Mail\Base\Utils::getEmailAddressDomain($sEmail), true);
		if (!$oDomain) {
			throw new ClientException(Notifications::DomainNotAllowed->value, null, "{$sEmail} has no domain configuration");
		}
		if (!$oDomain->ValidateWhiteList($sEmail)) {
			throw new ClientException(Notifications::AccountNotAllowed->value, null, "{$sEmail} not whitelisted");
		}
		return $oDomain;
	}
}
