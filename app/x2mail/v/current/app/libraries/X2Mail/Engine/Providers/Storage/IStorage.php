<?php

namespace X2Mail\Engine\Providers\Storage;

interface IStorage
{
	/**
	 * @param \X2Mail\Engine\Model\Account|null $mAccount
	 */
	public function Put($mAccount, int $iStorageType, string $sKey, string $sValue) : bool;

	/**
	 * @param \X2Mail\Engine\Model\Account|null $mAccount
	 * @param mixed $mDefault = false
	 *
	 * @return mixed
	 */
	public function Get($mAccount, int $iStorageType, string $sKey, $mDefault = false);

	/**
	 * @param \X2Mail\Engine\Model\Account|null $mAccount
	 */
	public function Clear($mAccount, int $iStorageType, string $sKey) : bool;

	/**
	 * @param \X2Mail\Engine\Model\Account|string $mAccount
	 */
	public function DeleteStorage($mAccount) : bool;

	/**
	 * @param \X2Mail\Engine\Model\Account|string|null $mAccount
	 */
	public function GenerateFilePath($mAccount, int $iStorageType, bool $bMkDir = false) : string;

	public function GC() : void;

	public function IsLocal() : bool;
}
