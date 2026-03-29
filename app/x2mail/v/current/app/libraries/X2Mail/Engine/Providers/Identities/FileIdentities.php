<?php

namespace X2Mail\Engine\Providers\Identities;

use X2Mail\Engine\Model\Account;
use X2Mail\Engine\Model\Identity;
use X2Mail\Engine\Providers\Storage;

class FileIdentities implements IIdentities
{
	/**
	 * @var Storage
	 */
	private $localStorageProvider;

	/**
	 * FileIdentities constructor.
	 * @param Storage $localStorageProvider
	 */
	public function __construct(Storage $localStorageProvider)
	{
		$this->localStorageProvider = $localStorageProvider;
	}

	/**
	 * @inheritDoc
	 */
	public function GetIdentities(Account $account): array
	{
		$data = $this->localStorageProvider->Get($account, Storage\Enumerations\StorageType::CONFIG->value, 'identities');
		$subIdentities = \json_decode($data, true) ?? [];
		$result = [];

		foreach ($subIdentities as $subIdentity) {
			$identity = new Identity();
			$identity->FromJSON($subIdentity);

			if (!$identity->Validate()) {
				continue;
			}
			if ($identity->IsAccountIdentities()) {
				$identity->SetEmail($account->Email());
			}
			$result[] = $identity;
		}

		return $result;
	}

	/**
	 * @inheritDoc
	 */
	public function SetIdentities(Account $account, array $identities): void
	{
		$jsons = \array_map(function ($identity) {
			return $identity->ToSimpleJSON();
		}, $identities);
		$this->localStorageProvider->Put($account, Storage\Enumerations\StorageType::CONFIG->value, 'identities', \json_encode($jsons));
	}

	/**
	 * @inheritDoc
	 */
	public function SupportsStore(): bool
	{
		return true;
	}

	public function Name(): string
	{
		return "File";
	}
}
