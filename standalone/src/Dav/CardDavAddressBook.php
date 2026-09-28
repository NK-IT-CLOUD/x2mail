<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Dav;

use Psr\Cache\CacheItemPoolInterface;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Reader;
use X2Mail\Engine\Providers\AddressBook\AddressBookInterface;
use X2Mail\Engine\Providers\AddressBook\Classes\Contact;
use X2Mail\Engine\UUID;
use X2Mail\Standalone\Log;

/**
 * Maps the engine's AddressBookInterface onto CardDAV (S1's CardDavClient).
 * Every DAV failure is swallowed here: mail must never depend on the
 * address book, so callers only ever see an empty result plus a log line.
 */
final class CardDavAddressBook implements AddressBookInterface
{
    private const CACHE_TTL_SECONDS = 300;

    private const FILENAME_SAFE_UID = '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,200}\z/';

    /**
     * Book list for the lifetime of this request; the cache only spans requests.
     *
     * @var list<AddressBookRef>|null
     */
    private ?array $allBooks = null;

    public function __construct(
        private readonly CardDavApi $client,
        private readonly DavContext $ctx,
        private readonly CacheItemPoolInterface $cache,
        private readonly Log $log,
    ) {
    }

    public function IsSupported(): bool
    {
        try {
            return !$this->isBlocked();
        } catch (\Throwable $e) {
            $this->handleUnexpected($e);
            return false;
        }
    }

    public function SetEmail(string $sEmail): bool
    {
        return true;
    }

    public function Sync(): bool
    {
        return true;
    }

    public function Export(string $sType = 'vcf'): bool
    {
        return false;
    }

    public function ContactSave(Contact $oContact): bool
    {
        try {
            if ($this->isBlocked()) {
                return false;
            }
            $vcard = $oContact->vCard;
            if ($vcard === null) {
                return false;
            }

            $book = $this->personalBook();
            if ($book === null) {
                return false;
            }

            $uid = $this->propertyValue($vcard, 'UID');
            if ($uid === '') {
                $uid = UUID::generate();
                $vcard->remove('UID');
                $vcard->add('UID', $uid);
            }

            $existing = $this->findCardByUid($book, $uid);
            $vcardData = $vcard->serialize();
            $saved = $existing !== null
                ? $this->client->put($book, $this->lastSegment($existing->href), $vcardData, $existing->etag)
                : $this->client->put($book, $this->filenameForUid($uid), $vcardData, null);

            $oContact->id = (string) \abs(\crc32($uid));
            $oContact->IdContactStr = $uid;
            $oContact->Etag = $saved->etag;
            return true;
        } catch (DavException $e) {
            $this->handleDavException($e);
            return false;
        } catch (\Throwable $e) {
            $this->handleUnexpected($e);
            return false;
        }
    }

    /** @param array<int, mixed> $aContactIds */
    public function DeleteContacts(array $aContactIds): bool
    {
        try {
            if ($this->isBlocked()) {
                return false;
            }

            $book = $this->personalBook();
            if ($book === null) {
                return false;
            }

            $byUid = [];
            foreach ($this->client->index($book) as $card) {
                $vcard = $this->tryParse($card->vcard);
                $uid = $vcard !== null ? $this->propertyValue($vcard, 'UID') : '';
                if ($uid !== '') {
                    $byUid[$uid] = $card;
                }
            }

            $ok = true;
            foreach ($aContactIds as $id) {
                $card = $this->findByUidOrCrc32($byUid, (string) $id);
                if ($card === null) {
                    $ok = false;
                    continue;
                }
                $this->client->delete($card->href, $card->etag);
            }
            return $ok;
        } catch (DavException $e) {
            $this->handleDavException($e);
            return false;
        } catch (\Throwable $e) {
            $this->handleUnexpected($e);
            return false;
        }
    }

    public function DeleteAllContacts(string $sEmail): bool
    {
        return false;
    }

    /** @return list<Contact> */
    public function GetContacts(int $iOffset = 0, int $iLimit = 20, string $sSearch = '', int &$iResultCount = 0): array
    {
        $iResultCount = 0;
        try {
            if ($this->isBlocked()) {
                return [];
            }

            $books = $this->books();

            $contacts = [];
            $broken = 0;
            $this->forEachCard($books, $this->searching($sSearch, 1000), function (CardRef $card, AddressBookRef $book) use (&$contacts, &$broken): bool {
                $contact = $this->withParsedCard($card, $broken, fn (VCard $vcard): ?Contact => $this->contactFromParsed($vcard, $card, $book));
                if ($contact !== null) {
                    $contacts[] = $contact;
                }
                return true;
            });
            $this->logSkippedCards($broken);

            \usort($contacts, fn (Contact $a, Contact $b): int => \strcasecmp($this->fnOf($a), $this->fnOf($b)));

            $iResultCount = \count($contacts);
            return \array_slice($contacts, \max(0, $iOffset), \max(0, $iLimit));
        } catch (DavException $e) {
            $this->handleDavException($e);
            return [];
        } catch (\Throwable $e) {
            $this->handleUnexpected($e);
            return [];
        }
    }

    public function GetContactByEmail(string $sEmail): ?Contact
    {
        if ($sEmail === '') {
            return null;
        }

        try {
            if ($this->isBlocked()) {
                return null;
            }

            $books = $this->books();

            $found = null;
            $broken = 0;
            $this->forEachCard($books, $this->searching($sEmail, 5), function (CardRef $card, AddressBookRef $book) use (&$found, $sEmail, &$broken): bool {
                $match = $this->withParsedCard($card, $broken, function (VCard $vcard) use ($card, $book, $sEmail): ?Contact {
                    foreach ($vcard->select('EMAIL') as $emailProp) {
                        if (\strcasecmp((string) $emailProp, $sEmail) === 0) {
                            return $this->contactFromParsed($vcard, $card, $book);
                        }
                    }
                    return null;
                });
                if ($match !== null) {
                    $found = $match;
                    return false;
                }
                return true;
            });
            $this->logSkippedCards($broken);
            return $found;
        } catch (DavException $e) {
            $this->handleDavException($e);
            return null;
        } catch (\Throwable $e) {
            $this->handleUnexpected($e);
            return null;
        }
    }

    /** @param mixed $mID */
    public function GetContactByID($mID, bool $bIsStrID = false): ?Contact
    {
        try {
            if ($this->isBlocked()) {
                return null;
            }

            // Same personal-first order as GetSuggestions, so a crc32 collision prefers the personal card.
            $books = $this->orderedForSuggestions($this->books());

            $found = null;
            $broken = 0;
            $idStr = (string) $mID;
            $this->forEachCard($books, $this->client->index(...), function (CardRef $entry, AddressBookRef $book) use (&$found, $idStr, &$broken): bool {
                $uid = $this->withParsedCard($entry, $broken, fn (VCard $vcard): string => $this->propertyValue($vcard, 'UID'));
                if ($uid === null || $uid === '' || ($uid !== $idStr && (string) \abs(\crc32($uid)) !== $idStr)) {
                    return true;
                }
                $card = $this->client->get($entry->href);
                if ($card === null) {
                    return true;
                }
                $found = $this->withParsedCard($card, $broken, fn (VCard $vcard): ?Contact => $this->contactFromParsed($vcard, $card, $book));
                return $found === null;
            });
            $this->logSkippedCards($broken);
            return $found;
        } catch (DavException $e) {
            $this->handleDavException($e);
            return null;
        } catch (\Throwable $e) {
            $this->handleUnexpected($e);
            return null;
        }
    }

    /** @return list<array{0: string, 1: string}> */
    public function GetSuggestions(string $sSearch, int $iLimit = 20): array
    {
        if (\mb_strlen($sSearch) < 2 || $iLimit < 1) {
            return [];
        }

        try {
            if ($this->isBlocked()) {
                return [];
            }

            $books = $this->orderedForSuggestions($this->books());

            $suggestions = [];
            $seen = [];
            $broken = 0;
            $this->forEachCard($books, $this->searching($sSearch, $iLimit), function (CardRef $card) use (&$suggestions, &$seen, $iLimit, &$broken): bool {
                $result = $this->withParsedCard($card, $broken, function (VCard $vcard) use (&$suggestions, &$seen, $iLimit): bool {
                    $displayName = $this->displayNameOf($vcard);
                    foreach ($vcard->select('EMAIL') as $emailProp) {
                        $email = (string) $emailProp;
                        if ($email === '') {
                            continue;
                        }
                        $key = \mb_strtolower($email);
                        if (isset($seen[$key])) {
                            continue;
                        }
                        $seen[$key] = true;
                        $suggestions[] = [$email, $displayName];
                        if (\count($suggestions) >= $iLimit) {
                            return false;
                        }
                    }
                    return true;
                });
                return $result ?? true;
            });
            $this->logSkippedCards($broken);
            return $suggestions;
        } catch (DavException $e) {
            $this->handleDavException($e);
            return [];
        } catch (\Throwable $e) {
            $this->handleUnexpected($e);
            return [];
        }
    }

    /** @param array<int, mixed> $aEmails */
    public function IncFrec(array $aEmails, bool $bCreateAuto = true): bool
    {
        return false;
    }

    public function Test(): string
    {
        try {
            if ($this->isBlocked()) {
                return 'dav: user temporarily blocked after an authentication failure';
            }
            $this->books();
            return '';
        } catch (DavException $e) {
            $this->handleDavException($e);
            return $e->getMessage();
        } catch (\Throwable $e) {
            $this->handleUnexpected($e);
            return $e->getMessage();
        }
    }

    // --- Books & caching ---

    /** @return list<AddressBookRef> */
    private function books(): array
    {
        $all = $this->allBooks ??= $this->loadBooks();

        if ($this->ctx->includeSystem) {
            return $all;
        }
        return \array_values(\array_filter($all, static fn (AddressBookRef $b): bool => !$b->system));
    }

    /** @return list<AddressBookRef> */
    private function loadBooks(): array
    {
        $item = $this->cache->getItem($this->booksCacheKey());
        if ($item->isHit()) {
            /** @var list<AddressBookRef> */
            return $item->get();
        }
        $all = $this->client->addressBooks();
        $item->set($all);
        $item->expiresAfter(self::CACHE_TTL_SECONDS);
        $this->cache->save($item);
        return $all;
    }

    /**
     * @param list<AddressBookRef> $books
     * @return list<AddressBookRef>
     */
    private function orderedForSuggestions(array $books): array
    {
        $personal = [];
        $system = [];
        $app = [];
        foreach ($books as $b) {
            if ($b->system) {
                $system[] = $b;
            } elseif ($b->appGenerated) {
                $app[] = $b;
            } else {
                $personal[] = $b;
            }
        }
        return [...$personal, ...$system, ...$app];
    }

    /** The writable book to save into: contacts/, else the first writable non-z- book, only if writablePersonal is set. */
    private function personalBook(): ?AddressBookRef
    {
        if (!$this->ctx->writablePersonal) {
            return null;
        }

        $writable = \array_values(\array_filter($this->books(), static fn (AddressBookRef $b): bool => $b->writable));
        foreach ($writable as $b) {
            if ($b->name === 'contacts') {
                return $b;
            }
        }
        foreach ($writable as $b) {
            if (!\str_starts_with($b->name, 'z-')) {
                return $b;
            }
        }
        return null;
    }

    // --- Card <-> Contact ---

    private function tryParse(string $vcardText): ?VCard
    {
        try {
            $node = Reader::read($vcardText);
        } catch (\Throwable) {
            return null;
        }
        return $node instanceof VCard ? $node : null;
    }

    /**
     * Parses $card and runs $work on the result, catching everything: Reader::read
     * and Sabre's own conversion/validation (Contact::setVCard(), select(), casts
     * to string) can all throw on a vCard written by another client. On any
     * failure — or a parse result that isn't a VCard — this returns null and
     * increments $broken by reference, so the caller logs one summary line for
     * the whole call instead of one line per broken card. Never logs card
     * content or the exception message (which could contain arbitrary vCard
     * fragments).
     *
     * @template T
     * @param callable(VCard): T $work
     * @return T|null
     */
    private function withParsedCard(CardRef $card, int &$broken, callable $work): mixed
    {
        try {
            $node = Reader::read($card->vcard);
            if (!$node instanceof VCard) {
                ++$broken;
                return null;
            }
            return $work($node);
        } catch (\Throwable) {
            ++$broken;
            return null;
        }
    }

    private function logSkippedCards(int $broken): void
    {
        if ($broken > 0) {
            $this->log->warning(\sprintf('carddav: skipped %d unreadable cards', $broken));
        }
    }

    /** Last line of defence: logs without ever including the exception message, which could carry vCard content. */
    private function handleUnexpected(\Throwable $e): void
    {
        $this->log->warning('carddav: unexpected error (' . \get_class($e) . ')');
    }

    private function contactFromParsed(VCard $vcard, CardRef $card, AddressBookRef $book): ?Contact
    {
        $uid = $this->propertyValue($vcard, 'UID');
        if ($uid === '') {
            return null;
        }

        $contact = new Contact();
        $contact->setVCard($vcard);
        $contact->id = (string) \abs(\crc32($uid));
        $contact->IdContactStr = $uid;
        $contact->ReadOnly = $book->href !== $this->personalBook()?->href;
        $contact->AddressBookName = $book->displayName !== '' ? $book->displayName : $book->name;
        $contact->Etag = $card->etag;
        return $contact;
    }

    /** @param array<string, CardRef> $byUid */
    private function findByUidOrCrc32(array $byUid, string $id): ?CardRef
    {
        foreach ($byUid as $uid => $card) {
            if ($uid === $id || (string) \abs(\crc32($uid)) === $id) {
                return $card;
            }
        }
        return null;
    }

    private function findCardByUid(AddressBookRef $book, string $uid): ?CardRef
    {
        foreach ($this->client->index($book) as $card) {
            $vcard = $this->tryParse($card->vcard);
            if ($vcard !== null && $this->propertyValue($vcard, 'UID') === $uid) {
                return $card;
            }
        }
        return null;
    }

    /** Reads a single-valued property via select(), never through the magic __get PHPStan cannot type. */
    private function propertyValue(VCard $vcard, string $name): string
    {
        $matches = $vcard->select($name);
        $first = \reset($matches);
        return $first instanceof \Sabre\VObject\Property ? (string) $first : '';
    }

    private function displayNameOf(VCard $vcard): string
    {
        $fn = $this->propertyValue($vcard, 'FN');
        return $fn !== '' ? $fn : $this->propertyValue($vcard, 'NICKNAME');
    }

    /**
     * Searches every book and calls $onCard for each result, isolating one book's
     * failure from the rest: an auth failure logs once, sets the block marker and
     * stops the whole iteration immediately (keeping whatever was already
     * collected); any other DavException is remembered, that book is skipped, and
     * iteration continues — at most one warning line is logged for the whole call.
     * $onCard returns false to stop the iteration early (e.g. once a match or the
     * requested limit is found).
     *
     * @param list<AddressBookRef> $books
     * @param callable(AddressBookRef): list<CardRef> $fetch
     * @param callable(CardRef, AddressBookRef): bool $onCard
     */
    private function forEachCard(array $books, callable $fetch, callable $onCard): void
    {
        $failed = null;
        foreach ($books as $book) {
            try {
                $cards = $fetch($book);
            } catch (DavException $e) {
                if ($e->authFailed()) {
                    $this->handleDavException($e);
                    return;
                }
                $failed ??= $e;
                continue;
            }

            foreach ($cards as $card) {
                if (!$onCard($card, $book)) {
                    if ($failed !== null) {
                        $this->handleDavException($failed);
                    }
                    return;
                }
            }
        }

        if ($failed !== null) {
            $this->handleDavException($failed);
        }
    }

    private function fnOf(Contact $contact): string
    {
        $vcard = $contact->vCard;
        return $vcard === null ? '' : $this->propertyValue($vcard, 'FN');
    }

    private function filenameForUid(string $uid): string
    {
        if (\preg_match(self::FILENAME_SAFE_UID, $uid) === 1) {
            return $uid . '.vcf';
        }
        return UUID::generate() . '.vcf';
    }

    /** @return callable(AddressBookRef): list<CardRef> */
    private function searching(string $text, int $limit): callable
    {
        return fn (AddressBookRef $book): array => $this->client->search($book, $text, $limit);
    }

    private function lastSegment(string $href): string
    {
        $trimmed = \rtrim($href, '/');
        $pos = \strrpos($trimmed, '/');
        return $pos === false ? $trimmed : \substr($trimmed, $pos + 1);
    }

    // --- Blocking & errors ---

    private function isBlocked(): bool
    {
        return $this->cache->getItem($this->blockCacheKey())->isHit();
    }

    private function block(): void
    {
        $item = $this->cache->getItem($this->blockCacheKey());
        $item->set(true);
        $item->expiresAfter(self::CACHE_TTL_SECONDS);
        $this->cache->save($item);
    }

    private function handleDavException(DavException $e): void
    {
        $this->log->warning('carddav: ' . $e->getMessage());
        if ($e->authFailed()) {
            $this->block();
        }
    }

    private function blockCacheKey(): string
    {
        return 'dav_blocked_' . $this->ctx->userKey;
    }

    private function booksCacheKey(): string
    {
        return 'dav_books_' . $this->ctx->userKey;
    }
}
