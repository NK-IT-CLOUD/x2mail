<?php

namespace X2Mail\Engine\Actions;

use X2Mail\Engine\Enumerations\Capa;

trait Filters
{
	private ?\X2Mail\Engine\Providers\Filters $oFiltersProvider = null;

	/**
	 * @throws \X2Mail\Mail\RuntimeException
	 */
	public function DoFilters() : array
	{
		$oAccount = $this->getAccountFromToken();

		if (!$this->GetCapa(Capa::SIEVE, $oAccount)) {
			return $this->FalseResponse();
		}

		return $this->DefaultResponse($this->FiltersProvider()->Load($oAccount));
	}

	/**
	 * @throws \X2Mail\Mail\RuntimeException
	 */
	public function DoFiltersScriptSave() : array
	{
		$oAccount = $this->getAccountFromToken();

		if (!$this->GetCapa(Capa::SIEVE, $oAccount)) {
			return $this->FalseResponse();
		}

		$sName = $this->GetActionParam('name', '');

		if ($this->GetActionParam('active', false)) {
//			$this->FiltersProvider()->ActivateScript($oAccount, $sName);
		}

		return $this->DefaultResponse($this->FiltersProvider()->Save(
			$oAccount, $sName, $this->GetActionParam('body', '')
		));
	}

	/**
	 * @throws \X2Mail\Mail\RuntimeException
	 */
	public function DoFiltersScriptActivate() : array
	{
		$oAccount = $this->getAccountFromToken();

		if (!$this->GetCapa(Capa::SIEVE, $oAccount)) {
			return $this->FalseResponse();
		}

		return $this->DefaultResponse($this->FiltersProvider()->ActivateScript(
			$oAccount, $this->GetActionParam('name', '')
		));
	}

	/**
	 * @throws \X2Mail\Mail\RuntimeException
	 */
	public function DoFiltersScriptDelete() : array
	{
		$oAccount = $this->getAccountFromToken();

		if (!$this->GetCapa(Capa::SIEVE, $oAccount)) {
			return $this->FalseResponse();
		}

		return $this->DefaultResponse($this->FiltersProvider()->DeleteScript(
			$oAccount, $this->GetActionParam('name', '')
		));
	}

	protected function FiltersProvider() : \X2Mail\Engine\Providers\Filters
	{
		if (!$this->oFiltersProvider) {
			$this->oFiltersProvider = new \X2Mail\Engine\Providers\Filters($this->fabrica('filters'));
		}
		return $this->oFiltersProvider;
	}
}
