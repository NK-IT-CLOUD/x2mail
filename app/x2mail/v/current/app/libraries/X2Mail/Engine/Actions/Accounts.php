<?php

namespace X2Mail\Engine\Actions;

use X2Mail\Engine\Enumerations\Capa;
use X2Mail\Engine\Exceptions\ClientException;
use X2Mail\Engine\Model\Account;
use X2Mail\Engine\Model\MainAccount;
use X2Mail\Engine\Model\AdditionalAccount;
use X2Mail\Engine\Model\Identity;
use X2Mail\Engine\Notifications;
use X2Mail\Engine\Providers\Identities;
use X2Mail\Engine\Providers\Storage\Enumerations\StorageType;
use X2Mail\Engine\Utils;
use X2Mail\Engine\IDN;

trait Accounts
{
	private ?\X2Mail\Engine\Providers\Identities $oIdentitiesProvider = null;

	protected function GetMainEmail(Account $oAccount)
	{
		return ($oAccount instanceof AdditionalAccount ? $this->getMainAccountFromToken() : $oAccount)->Email();
	}

	public function IdentitiesProvider(): Identities
	{
		if (null === $this->oIdentitiesProvider) {
			$this->oIdentitiesProvider = new Identities($this->fabrica('identities'));
		}

		return $this->oIdentitiesProvider;
	}

	public function GetAccounts(MainAccount $oAccount): array
	{
		if ($this->GetCapa(Capa::ADDITIONAL_ACCOUNTS->value)) {
			$sAccounts = $this->StorageProvider()->Get($oAccount,
				StorageType::CONFIG->value,
				'additionalaccounts'
			);
			$aAccounts = $sAccounts ? \json_decode($sAccounts, true)
				: \X2Mail\Engine\Upgrade::ConvertInsecureAccounts($this, $oAccount);
			if ($aAccounts && \is_array($aAccounts)) {
				return $aAccounts;
			}
		}

		return array();
	}

	public function SetAccounts(MainAccount $oAccount, array $aAccounts = array()): void
	{
		$sParentEmail = $oAccount->Email();
		if ($aAccounts) {
			$this->StorageProvider()->Put(
				$oAccount,
				StorageType::CONFIG->value,
				'additionalaccounts',
				\json_encode($aAccounts)
			);
		} else {
			$this->StorageProvider()->Clear(
				$oAccount,
				StorageType::CONFIG->value,
				'additionalaccounts'
			);
		}
	}

	/**
	 * Add/Edit additional account
	 * @throws \X2Mail\Mail\RuntimeException
	 */
	public function DoAccountSetup(): array
	{
		if (!$this->GetCapa(Capa::ADDITIONAL_ACCOUNTS->value)) {
			return $this->FalseResponse();
		}

		$oMainAccount = $this->getMainAccountFromToken();
		$aAccounts = $this->GetAccounts($oMainAccount);

		$sEmail = \trim($this->GetActionParam('email', ''));
		$oPassword = new \X2Mail\Engine\SensitiveString($this->GetActionParam('password', ''));
		$bNew = !empty($this->GetActionParam('new', 1));

		if ($bNew || \strlen($oPassword)) {
			/** @var \X2Mail\Engine\Model\AdditionalAccount $oNewAccount */
			$oNewAccount = $this->LoginProcess($sEmail, $oPassword, false);
			$sEmail = $oNewAccount->Email();
			$aAccount = $oNewAccount->asTokenArray($oMainAccount);
		} else {
			$aAccount = \X2Mail\Engine\Model\AdditionalAccount::convertArray($aAccounts[$sEmail]);
		}

		if ($bNew) {
			if ($oMainAccount->Email() === $sEmail || isset($aAccounts[$sEmail])) {
				throw new ClientException(Notifications::AccountAlreadyExists->value);
			}
		} else if (!isset($aAccounts[$sEmail])) {
			throw new ClientException(Notifications::AccountDoesNotExist->value);
		}

		$aAccounts[$sEmail] = $aAccount;

		if ($aAccounts[$sEmail]) {
			$aAccounts[$sEmail]['name'] = \trim($this->GetActionParam('name', ''));
			$this->SetAccounts($oMainAccount, $aAccounts);
		}

		return $this->TrueResponse();
	}

	protected function loadAdditionalAccountImapClient(string $sEmail): \X2Mail\Mail\Imap\ImapClient
	{
		$sEmail = IDN::emailToAscii($sEmail);
		if (!\strlen($sEmail)) {
			throw new ClientException(Notifications::AccountDoesNotExist->value);
		}

		$oMainAccount = $this->getMainAccountFromToken();
		$aAccounts = $this->GetAccounts($oMainAccount);
		if (!isset($aAccounts[$sEmail])) {
			throw new ClientException(Notifications::AccountDoesNotExist->value);
		}
		$oAccount = AdditionalAccount::NewInstanceFromTokenArray($this, $aAccounts[$sEmail]);
		if (!$oAccount) {
			throw new ClientException(Notifications::AccountDoesNotExist->value);
		}

		$oImapClient = new \X2Mail\Mail\Imap\ImapClient;
		$oImapClient->SetLogger($this->Logger());
		$this->imapConnect($oAccount, false, $oImapClient);
		return $oImapClient;
	}

	public function DoAccountUnread(): array
	{
		$oImapClient = $this->loadAdditionalAccountImapClient($this->GetActionParam('email', ''));
		$oInfo = $oImapClient->FolderStatus('INBOX');
		return $this->DefaultResponse([
			'unreadEmails' => \max(0, $oInfo->UNSEEN)
		]);
	}

	/**
	 * Imports all mail from AdditionalAccount into MainAccount
	 */
	public function DoAccountImport(): array
	{
		$sEmail = $this->GetActionParam('email', '');
		$oImapSource = $this->loadAdditionalAccountImapClient($sEmail);

		$oMainAccount = $this->getMainAccountFromToken();
		$oImapTarget = new \X2Mail\Mail\Imap\ImapClient;
		$oImapTarget->SetLogger($this->Logger());
		$this->imapConnect($oMainAccount, false, $oImapTarget);

		$oSync = new \X2Mail\Engine\Imap\Sync;
		$oSync->oImapSource = $oImapSource;
		$oSync->oImapTarget = $oImapTarget;

		$rootfolder = $this->GetActionParam('rootfolder', '') ?: $sEmail;
		$oSync->import($rootfolder);
		exit;
	}

	/**
	 * @throws \X2Mail\Mail\RuntimeException
	 */
	public function DoAccountDelete(): array
	{
		$oMainAccount = $this->getMainAccountFromToken();

		if (!$this->GetCapa(Capa::ADDITIONAL_ACCOUNTS->value)) {
			return $this->FalseResponse();
		}

		$sEmailToDelete = \trim($this->GetActionParam('emailToDelete', ''));
		$sEmailToDelete = IDN::emailToAscii($sEmailToDelete);

		$aAccounts = $this->GetAccounts($oMainAccount);

		if (\strlen($sEmailToDelete) && isset($aAccounts[$sEmailToDelete])) {
			$bReload = false;
			$oAccount = $this->getAccountFromToken();
			if ($oAccount instanceof AdditionalAccount && $oAccount->Email() === $sEmailToDelete) {
//				$this->SetAdditionalAuthToken(null);
				\X2Mail\Engine\Cookies::clear(self::AUTH_ADDITIONAL_TOKEN_KEY);
				$bReload = true;
			}

			unset($aAccounts[$sEmailToDelete]);
			$this->SetAccounts($oMainAccount, $aAccounts);

			return $this->TrueResponse(array('Reload' => $bReload));
		}

		return $this->FalseResponse();
	}

	public function getAccountData(Account $oAccount): array
	{
		$oConfig = $this->Config();
		$minRefreshInterval = (int) $oConfig->Get('webmail', 'min_refresh_interval', 5);
		$aResult = [
//			'Email' => IDN::emailToUtf8($oAccount->Email()),
			'Email' => $oAccount->Email(),
			'accountHash' => $oAccount->Hash(),
			'mainEmail' => \X2Mail\Engine\Api::Actions()->getMainAccountFromToken()->Email(),
			'contactsAllowed' => $this->AddressBookProvider($oAccount)->IsActive(),
			'HideUnsubscribed' => false,
			'useThreads' => (bool) $oConfig->Get('defaults', 'mail_use_threads', false),
			'threadAlgorithm' => '',
			'ReplySameFolder' => (bool) $oConfig->Get('defaults', 'mail_reply_same_folder', false),
			'HideDeleted' => true,
			'ShowUnreadCount' => false,
			'UnhideKolabFolders' => false,
			'CheckMailInterval' => \max(15, $minRefreshInterval)
		];
		$oSettingsLocal = $this->SettingsProvider(true)->Load($oAccount);
		if ($oSettingsLocal instanceof \X2Mail\Engine\Settings) {
			$aResult['SentFolder'] = (string) $oSettingsLocal->GetConf('SentFolder', '');
			$aResult['DraftsFolder'] = (string) $oSettingsLocal->GetConf('DraftsFolder', '');
			$aResult['JunkFolder'] = (string) $oSettingsLocal->GetConf('JunkFolder', '');
			$aResult['TrashFolder'] = (string) $oSettingsLocal->GetConf('TrashFolder', '');
			$aResult['ArchiveFolder'] = (string) $oSettingsLocal->GetConf('ArchiveFolder', '');
			$aResult['HideUnsubscribed'] = (bool) $oSettingsLocal->GetConf('HideUnsubscribed', $aResult['HideUnsubscribed']);
			$aResult['useThreads'] = (bool) $oSettingsLocal->GetConf('UseThreads', $aResult['useThreads']);
			$aResult['threadAlgorithm'] = (string) $oSettingsLocal->GetConf('threadAlgorithm', $aResult['threadAlgorithm']);
			$aResult['ReplySameFolder'] = (bool) $oSettingsLocal->GetConf('ReplySameFolder', $aResult['ReplySameFolder']);
			$aResult['HideDeleted'] = (bool)$oSettingsLocal->GetConf('HideDeleted', $aResult['HideDeleted']);
			$aResult['ShowUnreadCount'] = (bool)$oSettingsLocal->GetConf('ShowUnreadCount', $aResult['ShowUnreadCount']);
			$aResult['UnhideKolabFolders'] = (bool)$oSettingsLocal->GetConf('UnhideKolabFolders', $aResult['UnhideKolabFolders']);
			$aResult['CheckMailInterval'] = \max((int) $oSettingsLocal->GetConf('CheckMailInterval', $aResult['CheckMailInterval']), $minRefreshInterval);
/*
			foreach ($oSettingsLocal->toArray() as $key => $value) {
				$aResult[\lcfirst($key)] = $value;
			}
			$aResult['junkFolder'] = $aResult['spamFolder'];
			unset($aResult['checkableFolder']);
			unset($aResult['theme']);
*/
		}
		return $aResult;
	}

	/**
	 * @throws \X2Mail\Mail\RuntimeException
	 */
	public function DoAccountSwitch(): array
	{
		if ($this->switchAccount(\trim($this->GetActionParam('Email', '')))) {
			$oAccount = $this->getAccountFromToken();
			$aResult = $this->getAccountData($oAccount);
//			$this->Plugins()->InitAppData($bAdmin, $aResult, $oAccount);
			return $this->DefaultResponse($aResult);
		}
		return $this->FalseResponse();
	}

	/**
	 * @throws \X2Mail\Mail\RuntimeException
	 */
	public function DoIdentityUpdate(): array
	{
		$oAccount = $this->getAccountFromToken();

		$oIdentity = new Identity();
		if (!$oIdentity->FromJSON($this->GetActionParams(), true)) {
			throw new ClientException(Notifications::InvalidInputArgument->value);
		}
/*		// TODO: verify private key for certificate?
		if ($oIdentity->smimeCertificate && $oIdentity->smimeKey) {
			new \X2Mail\Engine\SMime\Certificate($oIdentity->smimeCertificate, $oIdentity->smimeKey);
		}
*/
		$this->IdentitiesProvider()->UpdateIdentity($oAccount, $oIdentity);
		return $this->TrueResponse();
	}

	/**
	 * @throws \X2Mail\Mail\RuntimeException
	 */
	public function DoIdentityDelete(): array
	{
		$oAccount = $this->getAccountFromToken();

		if (!$this->GetCapa(Capa::IDENTITIES->value)) {
			return $this->FalseResponse();
		}

		$sId = \trim($this->GetActionParam('idToDelete', ''));
		if (empty($sId)) {
			throw new ClientException(Notifications::UnknownError->value);
		}

		$this->IdentitiesProvider()->DeleteIdentity($oAccount, $sId);
		return $this->TrueResponse();
	}

	/**
	 * @throws \X2Mail\Mail\RuntimeException
	 */
	public function DoAccountsAndIdentitiesSortOrder(): array
	{
		$aAccounts = $this->GetActionParam('Accounts', null);
		$aIdentities = $this->GetActionParam('Identities', null);

		if (!\is_array($aAccounts) && !\is_array($aIdentities)) {
			return $this->FalseResponse();
		}

		if (\is_array($aAccounts) && 1 < \count($aAccounts)) {
			$oAccount = $this->getMainAccountFromToken();
			$aAccounts = \array_filter(\array_merge(
				\array_fill_keys($aAccounts, null),
				$this->GetAccounts($oAccount)
			));
			$this->SetAccounts($oAccount, $aAccounts);
		}

		return $this->DefaultResponse($this->LocalStorageProvider()->Put(
			$this->getAccountFromToken(),
			StorageType::CONFIG->value,
			'identities_order',
			\json_encode(array(
				'Identities' => \is_array($aIdentities) ? $aIdentities : array()
			))
		));
	}

	/**
	 * @throws \X2Mail\Mail\RuntimeException
	 */
	public function DoAccountsAndIdentities(): array
	{
		return $this->DefaultResponse(array(
			'Accounts' => \array_values(\array_map(function($value){
					return [
						'email' => IDN::emailToUtf8($value['email'] ?? $value[1]),
						'name' => $value['name'] ?? ''
					];
				},
				$this->GetAccounts($this->getMainAccountFromToken())
			)),
			'Identities' => $this->GetIdentities($this->getAccountFromToken())
		));
	}

	/**
	 * @return Identity[]
	 */
	public function GetIdentities(Account $oAccount): array
	{
		// A custom name for a single identity is also stored in this system
		$allowMultipleIdentities = $this->GetCapa(Capa::IDENTITIES->value);

		// Get all identities
		$identities = $this->IdentitiesProvider()->GetIdentities($oAccount, $allowMultipleIdentities);

		// Sort identities
		$orderString = $this->LocalStorageProvider()->Get($oAccount, StorageType::CONFIG->value, 'identities_order');
		$old = false;
		if (!$orderString) {
			$orderString = $this->StorageProvider()->Get($oAccount, StorageType::CONFIG->value, 'accounts_identities_order');
			$old = !!$orderString;
		}

		$order = \json_decode($orderString, true) ?? [];
		if (isset($order['Identities']) && \is_array($order['Identities']) && 1 < \count($order['Identities'])) {
			$list = \array_map(function ($item) {
				return ('' === $item) ? '---' : $item;
			}, $order['Identities']);

			\usort($identities, function ($a, $b) use ($list) {
				return \array_search($a->Id(true), $list) < \array_search($b->Id(true), $list) ? -1 : 1;
			});
		}

		if ($old) {
			$this->LocalStorageProvider()->Put(
				$oAccount,
				StorageType::CONFIG->value,
				'identities_order',
				\json_encode(array('Identities' => empty($order['Identities']) ? [] : $order['Identities']))
			);
			$this->StorageProvider()->Clear($oAccount, StorageType::CONFIG->value, 'accounts_identities_order');
		}

		return $identities;
	}

	public function GetIdentityByID(Account $oAccount, string $sID, bool $bFirstOnEmpty = false): ?Identity
	{
		$aIdentities = $this->GetIdentities($oAccount);

		foreach ($aIdentities as $oIdentity) {
			if ($oIdentity && $sID === $oIdentity->Id()) {
				return $oIdentity;
			}
		}

		return $bFirstOnEmpty && isset($aIdentities[0]) ? $aIdentities[0] : null;
	}

}
