<?php

namespace X2Mail\Engine\Providers;

class Filters extends \X2Mail\Engine\Providers\AbstractProvider
{
	/**
	 * @var \X2Mail\Engine\Providers\Filters\FiltersInterface
	 */
	private $oDriver;

	public function __construct(\X2Mail\Engine\Providers\Filters\FiltersInterface $oDriver)
	{
		$this->oDriver = $oDriver;
	}

	private static function handleException(\Throwable $oException, int $defNotification) : void
	{
		if ($oException instanceof \X2Mail\Mail\Net\Exceptions\SocketCanNotConnectToHostException) {
			throw new \X2Mail\Engine\Exceptions\ClientException(\X2Mail\Engine\Notifications::ConnectionError->value, $oException);
		}

		if ($oException instanceof \X2Mail\Mail\Sieve\Exceptions\NegativeResponseException) {
			throw new \X2Mail\Engine\Exceptions\ClientException(
				\X2Mail\Engine\Notifications::ClientViewError->value, $oException, \implode("\r\n", $oException->GetResponses())
			);
		}

		throw new \X2Mail\Engine\Exceptions\ClientException($defNotification, $oException);
	}

	public function Load(\X2Mail\Engine\Model\Account $oAccount) : array
	{
		try
		{
			return $this->IsActive() ? $this->oDriver->Load($oAccount) : array();
		}
		catch (\Throwable $oException)
		{
			self::handleException($oException, \X2Mail\Engine\Notifications::CantGetFilters->value);
		}
		return array();
	}

	public function Save(\X2Mail\Engine\Model\Account $oAccount, string $sScriptName, string $sRaw) : bool
	{
		try
		{
			return $this->IsActive()
				? $this->oDriver->Save($oAccount, $sScriptName, $sRaw)
				: false;
		}
		catch (\Throwable $oException)
		{
			self::handleException($oException, \X2Mail\Engine\Notifications::CantSaveFilters->value);
		}
		return false;
	}

	public function ActivateScript(\X2Mail\Engine\Model\Account $oAccount, string $sScriptName)
	{
		try
		{
			return $this->IsActive()
				? $this->oDriver->Activate($oAccount, $sScriptName)
				: false;
		}
		catch (\Throwable $oException)
		{
			self::handleException($oException, \X2Mail\Engine\Notifications::CantActivateFiltersScript->value);
		}
	}

	public function DeleteScript(\X2Mail\Engine\Model\Account $oAccount, string $sScriptName)
	{
		try
		{
			return $this->IsActive()
				? $this->oDriver->Delete($oAccount, $sScriptName)
				: false;
		}
		catch (\Throwable $oException)
		{
			self::handleException($oException, \X2Mail\Engine\Notifications::CantDeleteFiltersScript->value);
		}
	}

	public function IsActive() : bool
	{
		return $this->oDriver instanceof \X2Mail\Engine\Providers\Filters\FiltersInterface;
	}
}
