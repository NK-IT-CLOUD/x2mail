<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Tests\Dav;

use X2Mail\Standalone\Dav\AddressBookRef;
use X2Mail\Standalone\Dav\CardDavApi;
use X2Mail\Standalone\Dav\CardRef;
use X2Mail\Standalone\Dav\DavException;

/** Test double for CardDavApi: records calls, serves canned data, can be told to throw. */
final class FakeCardDavClient implements CardDavApi
{
    public int $addressBooksCallCount = 0;

    /** @var list<AddressBookRef> */
    public array $books = [];

    /** @var array<string, list<CardRef>> book href => cards returned by search() */
    public array $cardsByBookHref = [];

    /** @var list<array{book: AddressBookRef, text: string, limit: int}> */
    public array $searchCalls = [];

    /** @var list<AddressBookRef> */
    public array $indexCalls = [];

    /** @var list<array{book: AddressBookRef, filename: string, vcard: string, ifMatchEtag: ?string}> */
    public array $putCalls = [];

    /** @var list<array{href: string, etag: string}> */
    public array $deleteCalls = [];

    public ?DavException $addressBooksException = null;

    public ?DavException $searchException = null;

    /** @var array<string, DavException> book href => exception thrown on search() for that book only */
    public array $searchExceptionByBookHref = [];

    public ?DavException $putException = null;

    /** Any non-DavException \Throwable to raise from put(), e.g. a Sabre exception from serialize(). */
    public ?\Throwable $putGenericException = null;

    public ?CardRef $putResult = null;

    public function addressBooks(): array
    {
        ++$this->addressBooksCallCount;
        if ($this->addressBooksException !== null) {
            throw $this->addressBooksException;
        }
        return $this->books;
    }

    public function search(AddressBookRef $book, string $text, int $limit): array
    {
        $this->searchCalls[] = ['book' => $book, 'text' => $text, 'limit' => $limit];
        if (isset($this->searchExceptionByBookHref[$book->href])) {
            throw $this->searchExceptionByBookHref[$book->href];
        }
        if ($this->searchException !== null) {
            throw $this->searchException;
        }
        return $this->cardsByBookHref[$book->href] ?? [];
    }

    /** Serves the same cards as search(); a real server may return only UID in address-data. */
    public function index(AddressBookRef $book): array
    {
        $this->indexCalls[] = $book;
        if (isset($this->searchExceptionByBookHref[$book->href])) {
            throw $this->searchExceptionByBookHref[$book->href];
        }
        if ($this->searchException !== null) {
            throw $this->searchException;
        }
        return $this->cardsByBookHref[$book->href] ?? [];
    }

    public function get(string $href): ?CardRef
    {
        foreach ($this->cardsByBookHref as $cards) {
            foreach ($cards as $card) {
                if ($card->href === $href) {
                    return $card;
                }
            }
        }
        return null;
    }

    public function put(AddressBookRef $book, string $filename, string $vcard, ?string $ifMatchEtag): CardRef
    {
        $this->putCalls[] = ['book' => $book, 'filename' => $filename, 'vcard' => $vcard, 'ifMatchEtag' => $ifMatchEtag];
        if ($this->putGenericException !== null) {
            throw $this->putGenericException;
        }
        if ($this->putException !== null) {
            throw $this->putException;
        }
        return $this->putResult ?? new CardRef($book->href . $filename, '"new-etag"', $vcard);
    }

    public function delete(string $href, string $etag): void
    {
        $this->deleteCalls[] = ['href' => $href, 'etag' => $etag];
    }

    public function timeoutSeconds(): int
    {
        return 5;
    }
}
