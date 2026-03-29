<?php

namespace X2Mail\Engine\Providers\Files;

interface IFiles
{
	public function GenerateLocalFullFileName(\X2Mail\Engine\Model\Account $oAccount, string $sKey) : string;

	public function PutFile(\X2Mail\Engine\Model\Account $oAccount, string $sKey, /*resource*/ $rSource) : bool;

	public function MoveUploadedFile(\X2Mail\Engine\Model\Account $oAccount, string $sKey, string $sSource) : bool;

	/**
	 * @return resource|bool
	 */
	public function GetFile(\X2Mail\Engine\Model\Account $oAccount, string $sKey, string $sOpenMode = 'rb');

	/**
	 * @return string|bool
	 */
	public function GetFileName(\X2Mail\Engine\Model\Account $oAccount, string $sKey);

	public function Clear(\X2Mail\Engine\Model\Account $oAccount, string $sKey) : bool;

	/**
	 * @return int | bool
	 */
	public function FileSize(\X2Mail\Engine\Model\Account $oAccount, string $sKey);

	public function FileExists(\X2Mail\Engine\Model\Account $oAccount, string $sKey) : bool;

	public function GC(int $iTimeToClearInHours = 24) : bool;
}
