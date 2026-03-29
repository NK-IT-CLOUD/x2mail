<?php

namespace X2Mail\Engine\Actions;

use X2Mail\Engine\Enumerations\Capa;

trait Contacts
{
	/**
	 * @var \X2Mail\Engine\Providers\AddressBook
	 */
	protected $oAddressBookProvider = null;

	public function AddressBookProvider(?\X2Mail\Engine\Model\Account $oAccount = null): \X2Mail\Engine\Providers\AddressBook
	{
		if (null === $this->oAddressBookProvider) {
			$oDriver = null;
			try {
//				if ($this->oConfig->Get('contacts', 'enable', false)) {
				if ($this->GetCapa(Capa::CONTACTS->value)) {
					$oDriver = $this->fabrica('address-book', $oAccount);
				}
				if ($oAccount && $oDriver) {
					$oDriver->SetEmail($this->GetMainEmail($oAccount));
				}
			} catch (\Throwable $e) {
				\X2Mail\Engine\Log::error('AddressBook', $e->getMessage()."\n".$e->getTraceAsString());
				$oDriver = null;
//				$oDriver = new \X2Mail\Engine\Providers\AddressBook\PdoAddressBook();
			}
			$this->oAddressBookProvider = new \X2Mail\Engine\Providers\AddressBook($oDriver);
			$this->oAddressBookProvider->SetLogger($this->oLogger);
		}

		return $this->oAddressBookProvider;
	}

	public function DoContacts() : array
	{
		$oAccount = $this->getAccountFromToken();

		$sSearch = \trim($this->GetActionParam('Search', ''));
		$iOffset = (int) $this->GetActionParam('Offset', 0);
		$iLimit = (int) $this->GetActionParam('Limit', 20);
		$iOffset = 0 > $iOffset ? 0 : $iOffset;
		$iLimit = 0 > $iLimit ? 20 : $iLimit;

		$iResultCount = 0;
		$mResult = array();

		$oAbp = $this->AddressBookProvider($oAccount);
		if ($oAbp->IsActive()) {
			$iResultCount = 0;
			$mResult = $oAbp->GetContacts($iOffset, $iLimit, $sSearch, $iResultCount);
		}

		return $this->DefaultResponse(array(
			'Offset' => $iOffset,
			'Limit' => $iLimit,
			'Count' => $iResultCount,
			'Search' => $sSearch,
			'List' => $mResult
		));
	}

	public function DoContactsDelete() : array
	{
		$oAccount = $this->getAccountFromToken();
		$aUids = \explode(',', (string) $this->GetActionParam('uids', ''));

		$aFilteredUids = \array_filter(\array_map('intval', $aUids));

		$bResult = false;
		if (\count($aFilteredUids) && $this->AddressBookProvider($oAccount)->IsActive()) {
			$bResult = $this->AddressBookProvider($oAccount)->DeleteContacts($aFilteredUids);
		}

		return $this->DefaultResponse($bResult);
	}

	public function DoContactSave() : array
	{
		$oAccount = $this->getAccountFromToken();

		$bResult = false;
		$oContact = null;

		if ($this->HasActionParam('uid') && $this->HasActionParam('jCard')) {
			$oAddressBookProvider = $this->AddressBookProvider($oAccount);
			if ($oAddressBookProvider && $oAddressBookProvider->IsActive()) {
				$vCard = \Sabre\VObject\Reader::readJson($this->GetActionParam('jCard'));
				if ($vCard && $vCard instanceof \Sabre\VObject\Component\VCard) {
					$vCard->REV = \gmdate('Ymd\\THis\\Z');
					$vCard->PRODID = 'X2Mail-'.APP_VERSION;
					$sUid = \trim($this->GetActionParam('uid'));
					$oContact = $sUid ? $oAddressBookProvider->GetContactByID($sUid) : null;
					if (!$oContact) {
						$oContact = new \X2Mail\Engine\Providers\AddressBook\Classes\Contact();
					}
					$oContact->setVCard($vCard);
					$bResult = $oAddressBookProvider->ContactSave($oContact);
				}
			}
		}

		return $this->DefaultResponse(array(
			'ResultID' => $bResult ? $oContact->id : '',
			'Result' => $bResult
		));
	}

	public function UploadContacts(?array $aFile, int $iError) : array
	{
		$oAccount = $this->getAccountFromToken();

		$mResponse = false;

		if ($oAccount && UPLOAD_ERR_OK === $iError && \is_array($aFile)) {
			$sSavedName = 'upload-post-'.\md5($aFile['name'].$aFile['tmp_name']);
			if (!$this->FilesProvider()->MoveUploadedFile($oAccount, $sSavedName, $aFile['tmp_name'])) {
				$iError = \X2Mail\Engine\Enumerations\UploadError::ON_SAVING->value;
			} else {
				$mData = $this->FilesProvider()->GetFile($oAccount, $sSavedName);
				if ($mData) {
					$sFileStart = \fread($mData, 128);
					\rewind($mData);
					if (false !== $sFileStart) {
						$sFileStart = \trim($sFileStart);
						if (false !== \strpos($sFileStart, 'BEGIN:VCARD')) {
							$mResponse = $this->importContactsFromVcfFile($oAccount, $mData);
						} else if (false !== \strpos($sFileStart, ',') || false !== \strpos($sFileStart, ';')) {
							$mResponse = $this->importContactsFromCsvFile($oAccount, $mData, $sFileStart);
						}
					}
				}

				if (\is_resource($mData)) {
					\fclose($mData);
				}

				unset($mData);
				$this->FilesProvider()->Clear($oAccount, $sSavedName);
			}
		}

		if (UPLOAD_ERR_OK !== $iError) {
			$iClientError = 0;
			$sError = \X2Mail\Engine\Enumerations\UploadError::getUserMessage($iError, $iClientError);
			if (!empty($sError)) {
				return $this->FalseResponse($iClientError, $sError);
			}
		}

		return $this->DefaultResponse($mResponse);
	}

	public function RawContactsVcf() : bool
	{
		$oAccount = $this->getAccountFromToken();

		\header('Content-Type: text/x-vcard; charset=UTF-8');
		\header('Content-Disposition: attachment; filename="contacts.vcf"');
		\header('Accept-Ranges: none');
		\header('Content-Transfer-Encoding: binary');

		$this->Http()->ServerNoCache();

		$oAddressBookProvider = $this->AddressBookProvider($oAccount);
		return $oAddressBookProvider->IsActive() ?
			$oAddressBookProvider->Export('vcf') : false;
	}

	public function RawContactsCsv() : bool
	{
		$oAccount = $this->getAccountFromToken();

		\header('Content-Type: text/csv; charset=UTF-8');
		\header('Content-Disposition: attachment; filename="contacts.csv"');
		\header('Accept-Ranges: none');
		\header('Content-Transfer-Encoding: binary');

		$this->Http()->ServerNoCache();

		$oAddressBookProvider = $this->AddressBookProvider($oAccount);
		return $oAddressBookProvider->IsActive() ?
			$oAddressBookProvider->Export('csv') : false;
	}

	private function importContactsFromVcfFile(\X2Mail\Engine\Model\Account $oAccount, /*resource*/ $rFile): int
	{
		$iCount = 0;
		$oAddressBookProvider = $this->AddressBookProvider($oAccount);
		if (\is_resource($rFile) && $oAddressBookProvider && $oAddressBookProvider->IsActive()) {
			try
			{
				$this->logWrite('Import contacts from vcf');
				foreach (\X2Mail\Engine\Providers\AddressBook\Utils::VcfStreamToContacts($rFile) as $oContact) {
					if ($oAddressBookProvider->ContactSave($oContact)) {
						++$iCount;
					}
				}
			}
			catch (\Throwable $oExc)
			{
				$this->logException($oExc);
			}
		}
		return $iCount;
	}

	private function importContactsFromCsvFile(\X2Mail\Engine\Model\Account $oAccount, /*resource*/ $rFile, string $sFileStart): int
	{
		$iCount = 0;
		$oAddressBookProvider = $this->AddressBookProvider($oAccount);
		if (\is_resource($rFile) && $oAddressBookProvider && $oAddressBookProvider->IsActive()) {
			try
			{
				$this->logWrite('Import contacts from csv');
				$sDelimiter = ((int)\strpos($sFileStart, ',') > (int)\strpos($sFileStart, ';')) ? ',' : ';';
				foreach (\X2Mail\Engine\Providers\AddressBook\Utils::CsvStreamToContacts($rFile, $sDelimiter) as $oContact) {
					if ($oAddressBookProvider->ContactSave($oContact)) {
						++$iCount;
					}
				}
			}
			catch (\Throwable $oExc)
			{
				$this->logException($oExc);
			}
		}
		return $iCount;
	}

}
