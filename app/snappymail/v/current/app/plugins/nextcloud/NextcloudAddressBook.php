<?php

use RainLoop\Providers\AddressBook\AddressBookInterface;
use RainLoop\Providers\AddressBook\Classes\Contact;
use Sabre\VObject\Component\VCard;

class NextcloudAddressBook implements AddressBookInterface
{
	use \MailSo\Log\Inherit;

	private ?\OCP\Contacts\IManager $cm = null;
	private string $addressBookKey = '';
	private string $sEmail = '';
	private ?array $userBookKeys = null;

	/**
	 * No-op stub — Actions/Contacts.php:25 calls this unconditionally.
	 * Not part of AddressBookInterface but required for compatibility
	 * with PdoAddressBook's CardDAV trait.
	 */
	public function setDAVClientConfig(?array $aConfig): void {}

	public function IsSupported(): bool
	{
		return $this->getManager()?->isEnabled() ?? false;
	}

	public function SetEmail(string $sEmail): bool
	{
		$this->sEmail = $sEmail;
		return true;
	}

	public function Sync(): bool
	{
		// No sync needed — we read/write NC directly
		return true;
	}

	public function Export(string $sType = 'vcf'): bool
	{
		if ('vcf' !== $sType) {
			return false;
		}

		$cm = $this->getManager();
		if (!$cm) {
			return false;
		}

		$results = $this->filterUserContacts(
			$cm->search('', ['FN'], ['limit' => 10000])
		);

		foreach ($results as $contact) {
			if (!empty($contact['UID'])) {
				$vCard = $this->contactToVCard($contact);
				if ($vCard) {
					echo $vCard->serialize();
				}
			}
		}

		return true;
	}

	public function ContactSave(Contact $oContact): bool
	{
		$cm = $this->getManager();
		if (!$cm) {
			return false;
		}

		$addressBookKey = $this->getDefaultAddressBookKey();
		if (!$addressBookKey) {
			return false;
		}

		$vCard = $oContact->vCard;
		if (!$vCard) {
			return false;
		}

		$properties = $this->vCardToProperties($vCard);

		// If contact has an existing NC id, include it for update
		if ($oContact->id && \is_numeric($oContact->id)) {
			$properties['id'] = (int) $oContact->id;
		}

		try {
			$result = $cm->createOrUpdate($properties, $addressBookKey);
		} catch (\Throwable $e) {
			$this->logException($e);
			return false;
		}

		if ($result && !empty($result['id'])) {
			$oContact->id = (string) $result['id'];
			return true;
		}

		return false;
	}

	public function DeleteContacts(array $aContactIds): bool
	{
		$cm = $this->getManager();
		if (!$cm) {
			return false;
		}

		$addressBookKey = $this->getDefaultAddressBookKey();
		if (!$addressBookKey) {
			return false;
		}

		$ok = true;
		foreach ($aContactIds as $id) {
			try {
				if (!$cm->delete((int) $id, $addressBookKey)) {
					$ok = false;
				}
			} catch (\Throwable $e) {
				$this->logException($e);
				$ok = false;
			}
		}

		return $ok;
	}

	public function DeleteAllContacts(string $sEmail): bool
	{
		// Not practical via IManager — would need to search+delete all
		return false;
	}

	public function GetContacts(int $iOffset = 0, int $iLimit = 20, string $sSearch = '', int &$iResultCount = 0): array
	{
		$cm = $this->getManager();
		if (!$cm) {
			return [];
		}

		// IManager::search doesn't support offset natively, fetch all and slice
		$allResults = $this->filterUserContacts(
			$cm->search($sSearch, ['FN', 'EMAIL', 'NICKNAME', 'TEL'], [])
		);

		$iResultCount = \count($allResults);
		$sliced = \array_slice($allResults, $iOffset, $iLimit);

		$contacts = [];
		foreach ($sliced as $ncContact) {
			$contact = $this->ncContactToContact($ncContact);
			if ($contact) {
				$contacts[] = $contact;
			}
		}

		return $contacts;
	}

	public function GetContactByEmail(string $sEmail): ?Contact
	{
		$cm = $this->getManager();
		if (!$cm) {
			return null;
		}

		$results = $this->filterUserContacts(
			$cm->search($sEmail, ['EMAIL'], ['strict_search' => true])
		);

		if ($results) {
			return $this->ncContactToContact(\reset($results));
		}

		return null;
	}

	public function GetContactByID($mID, bool $bIsStrID = false): ?Contact
	{
		$cm = $this->getManager();
		if (!$cm) {
			return null;
		}

		// Try UID search first — NC IManager doesn't support numeric id search directly.
		// SnappyMail stores UID as IdContactStr; the UI may pass either UID or numeric id.
		$searchValue = (string) $mID;
		$results = $this->filterUserContacts(
			$cm->search($searchValue, ['UID'], ['strict_search' => true])
		);

		if (!$results && !$bIsStrID) {
			// Fallback: search all and filter by NC row id
			$allResults = $this->filterUserContacts(
				$cm->search('', ['FN'])
			);
			$results = \array_filter($allResults, fn($c) => isset($c['id']) && $c['id'] == $mID);
		}

		if ($results) {
			return $this->ncContactToContact(\reset($results));
		}

		return null;
	}

	public function GetSuggestions(string $sSearch, int $iLimit = 20): array
	{
		$cm = $this->getManager();
		if (!$cm) {
			return [];
		}

		$results = $this->filterUserContacts(
			$cm->search($sSearch, ['FN', 'NICKNAME', 'EMAIL'], ['limit' => $iLimit])
		);

		$suggestions = [];
		foreach ($results as $contact) {
			if (empty($contact['UID'])) {
				continue;
			}

			$fullName = \trim($contact['FN'] ?? $contact['NICKNAME'] ?? '');
			$emails = $contact['EMAIL'] ?? '';
			if (!\is_array($emails)) {
				$emails = $emails ? [$emails] : [];
			}

			foreach ($emails as $email) {
				$emailValue = \is_array($email) ? ($email['value'] ?? '') : $email;
				if ($emailValue) {
					$suggestions[] = [$emailValue, $fullName];
				}
			}
		}

		return \array_slice($suggestions, 0, $iLimit);
	}

	public function IncFrec(array $aEmails, bool $bCreateAuto = true): bool
	{
		// No frequency tracking in NC Contacts
		return true;
	}

	public function Test(): string
	{
		$cm = $this->getManager();
		if (!$cm) {
			return 'Nextcloud ContactsManager not available';
		}
		if (!$cm->isEnabled()) {
			return 'Nextcloud Contacts not enabled';
		}

		$books = $cm->getUserAddressBooks();
		return 'Nextcloud Contacts OK (' . \count($books) . ' address books)';
	}

	// --- Private helpers ---

	private function getManager(): ?\OCP\Contacts\IManager
	{
		if (null === $this->cm) {
			if (\class_exists('OCP\\Server')) {
				try {
					$this->cm = \OCP\Server::get(\OCP\Contacts\IManager::class);
				} catch (\Throwable $e) {
					$this->logException($e);
				}
			}
		}
		return $this->cm;
	}

	/**
	 * Get keys of user-owned (non-system) address books for in-memory filtering.
	 * Does NOT mutate the shared IManager singleton.
	 */
	private function getUserBookKeys(): array
	{
		if (null === $this->userBookKeys) {
			$cm = $this->getManager();
			$this->userBookKeys = [];
			if ($cm) {
				foreach ($cm->getUserAddressBooks() as $book) {
					if (!$book->isSystemAddressBook()) {
						$this->userBookKeys[] = $book->getKey();
					}
				}
			}
		}
		return $this->userBookKeys;
	}

	/**
	 * Filter search results to user-owned address books only (in-memory, no singleton mutation).
	 * If no user-owned books exist, returns all results as fallback.
	 */
	private function filterUserContacts(array $results): array
	{
		$keys = $this->getUserBookKeys();
		if (!$keys) {
			return $results;
		}
		return \array_values(
			\array_filter($results, fn($c) => \in_array($c['addressbook-key'] ?? '', $keys))
		);
	}

	private function getDefaultAddressBookKey(): string
	{
		if ($this->addressBookKey) {
			return $this->addressBookKey;
		}

		$cm = $this->getManager();
		if (!$cm) {
			return '';
		}

		$books = $cm->getUserAddressBooks();

		// Prefer first non-system addressbook (typically "Contacts")
		foreach ($books as $book) {
			if (!$book->isSystemAddressBook()) {
				$this->addressBookKey = $book->getKey();
				return $this->addressBookKey;
			}
		}

		// Fallback: first available
		foreach ($books as $book) {
			$this->addressBookKey = $book->getKey();
			return $this->addressBookKey;
		}

		return '';
	}

	private function ncContactToContact(array $ncContact): ?Contact
	{
		if (empty($ncContact['UID'])) {
			return null;
		}

		$vCard = $this->contactToVCard($ncContact);
		if (!$vCard) {
			return null;
		}

		$contact = new Contact();
		$contact->id = (string) ($ncContact['id'] ?? '');
		$contact->IdContactStr = (string) $ncContact['UID'];
		$contact->setVCard($vCard);
		$contact->ReadOnly = false;

		return $contact;
	}

	private function contactToVCard(array $ncContact): ?VCard
	{
		// If NC returns raw vCard data, parse it directly
		if (!empty($ncContact['carddata'])) {
			try {
				$vCard = \Sabre\VObject\Reader::read($ncContact['carddata']);
				if ($vCard instanceof VCard) {
					return $vCard;
				}
			} catch (\Throwable $e) {
				$this->logException($e);
			}
		}

		// Build vCard from NC property arrays
		$vCard = new VCard();
		$vCard->UID = $ncContact['UID'] ?? \SnappyMail\UUID::generate();
		$vCard->FN = $ncContact['FN'] ?? '';

		if (!empty($ncContact['EMAIL'])) {
			$emails = \is_array($ncContact['EMAIL']) ? $ncContact['EMAIL'] : [$ncContact['EMAIL']];
			foreach ($emails as $email) {
				if (\is_array($email)) {
					$type = !empty($email['type']) ? \strtoupper($email['type']) : 'WORK';
					$vCard->add('EMAIL', $email['value'] ?? '', ['TYPE' => $type]);
				} else {
					$vCard->add('EMAIL', $email);
				}
			}
		}

		if (!empty($ncContact['TEL'])) {
			$tels = \is_array($ncContact['TEL']) ? $ncContact['TEL'] : [$ncContact['TEL']];
			foreach ($tels as $tel) {
				if (\is_array($tel)) {
					$type = !empty($tel['type']) ? \strtoupper($tel['type']) : 'WORK';
					$vCard->add('TEL', $tel['value'] ?? '', ['TYPE' => $type]);
				} else {
					$vCard->add('TEL', $tel);
				}
			}
		}

		if (!empty($ncContact['ORG'])) {
			$vCard->ORG = $ncContact['ORG'];
		}

		if (!empty($ncContact['TITLE'])) {
			$vCard->TITLE = $ncContact['TITLE'];
		}

		if (!empty($ncContact['NICKNAME'])) {
			$vCard->NICKNAME = $ncContact['NICKNAME'];
		}

		if (!empty($ncContact['ADR'])) {
			$addrs = \is_array($ncContact['ADR']) ? $ncContact['ADR'] : [$ncContact['ADR']];
			foreach ($addrs as $addr) {
				if (\is_array($addr)) {
					$vCard->add('ADR', $addr['value'] ?? '', !empty($addr['type']) ? ['TYPE' => \strtoupper($addr['type'])] : []);
				} else {
					$vCard->add('ADR', $addr);
				}
			}
		}

		if (!empty($ncContact['NOTE'])) {
			$vCard->NOTE = $ncContact['NOTE'];
		}

		$vCard->REV = \gmdate('Ymd\\THis\\Z');

		return $vCard;
	}

	private function vCardToProperties(VCard $vCard): array
	{
		$props = [];
		$props['UID'] = (string) ($vCard->UID ?? \SnappyMail\UUID::generate());
		$props['FN'] = (string) ($vCard->FN ?? '');

		if ($vCard->EMAIL) {
			$emails = [];
			foreach ($vCard->EMAIL as $email) {
				$type = $email['TYPE'] ? (string) $email['TYPE'] : '';
				$emails[] = ['value' => (string) $email, 'type' => $type ?: 'work'];
			}
			$props['EMAIL'] = $emails;
		}

		if ($vCard->TEL) {
			$tels = [];
			foreach ($vCard->TEL as $tel) {
				$type = $tel['TYPE'] ? (string) $tel['TYPE'] : '';
				$tels[] = ['value' => (string) $tel, 'type' => $type ?: 'work'];
			}
			$props['TEL'] = $tels;
		}

		if ($vCard->ORG) {
			$props['ORG'] = (string) $vCard->ORG;
		}

		if ($vCard->TITLE) {
			$props['TITLE'] = (string) $vCard->TITLE;
		}

		if ($vCard->NICKNAME) {
			$props['NICKNAME'] = (string) $vCard->NICKNAME;
		}

		if ($vCard->NOTE) {
			$props['NOTE'] = (string) $vCard->NOTE;
		}

		if ($vCard->ADR) {
			$addrs = [];
			foreach ($vCard->ADR as $adr) {
				$type = $adr['TYPE'] ? (string) $adr['TYPE'] : '';
				$addrs[] = ['value' => (string) $adr, 'type' => $type ?: 'work'];
			}
			$props['ADR'] = $addrs;
		}

		return $props;
	}
}
