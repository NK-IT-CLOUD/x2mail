<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Dav;

/**
 * The public surface of CardDavClient (S1). CardDavClient is final, so
 * callers that need a test double depend on this interface instead.
 */
interface CardDavApi
{
    /** @return list<AddressBookRef> */
    public function addressBooks(): array;

    /** @return list<CardRef> */
    public function search(AddressBookRef $book, string $text, int $limit): array;

    /**
     * Every card of a book with its etag, uncapped. address-data asks for
     * UID only; a server may still return the full vCard.
     *
     * @return list<CardRef>
     */
    public function index(AddressBookRef $book): array;

    public function get(string $href): ?CardRef;

    public function put(AddressBookRef $book, string $filename, string $vcard, ?string $ifMatchEtag): CardRef;

    public function delete(string $href, string $etag): void;

    public function timeoutSeconds(): int;
}
