<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Tests\Dav;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use X2Mail\Standalone\Dav\AddressBookRef;
use X2Mail\Standalone\Dav\CardDavClient;
use X2Mail\Standalone\Dav\DavException;

class CardDavClientTest extends TestCase
{
    private const DAV_ROOT = 'https://nc.example.org/remote.php/dav/';

    private const TOKEN = 'secret-token-abc';

    private const VCARD = "BEGIN:VCARD\r\nEND:VCARD\r\n";

    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private function fixture(string $name): string
    {
        return (string) \file_get_contents(__DIR__ . '/fixtures/' . $name);
    }

    /** @param list<Response|ConnectException> $responses */
    private function client(array $responses): CardDavClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $factory = new HttpFactory();
        return new CardDavClient(new Client(['handler' => $stack]), $factory, $factory, self::DAV_ROOT, self::TOKEN, 5);
    }

    private function contactsBook(): AddressBookRef
    {
        return new AddressBookRef(self::DAV_ROOT . 'addressbooks/users/nk/contacts/', 'contacts', 'Kontakte', true, false, false);
    }

    public function testAddressBooksDiscoversHomeAndFlags(): void
    {
        $client = $this->client([
            new Response(207, [], $this->fixture('principal.xml')),
            new Response(207, [], $this->fixture('home-set.xml')),
            new Response(207, [], $this->fixture('books.xml')),
        ]);

        $books = $client->addressBooks();

        self::assertCount(3, $this->history);
        self::assertSame(self::DAV_ROOT, (string) $this->history[0]['request']->getUri());
        self::assertSame('0', $this->history[0]['request']->getHeaderLine('Depth'));
        self::assertSame('Bearer ' . self::TOKEN, $this->history[0]['request']->getHeaderLine('Authorization'));

        self::assertSame('https://nc.example.org/remote.php/dav/principals/users/nk/', (string) $this->history[1]['request']->getUri());
        self::assertSame('0', $this->history[1]['request']->getHeaderLine('Depth'));
        self::assertSame('Bearer ' . self::TOKEN, $this->history[1]['request']->getHeaderLine('Authorization'));

        self::assertSame('https://nc.example.org/remote.php/dav/addressbooks/users/nk/', (string) $this->history[2]['request']->getUri());
        self::assertSame('1', $this->history[2]['request']->getHeaderLine('Depth'));
        self::assertSame('Bearer ' . self::TOKEN, $this->history[2]['request']->getHeaderLine('Authorization'));

        self::assertCount(5, $books);

        $byName = [];
        foreach ($books as $book) {
            $byName[$book->name] = $book;
        }

        self::assertTrue($byName['contacts']->writable);
        self::assertFalse($byName['contacts']->system);
        self::assertFalse($byName['contacts']->appGenerated);

        self::assertTrue($byName['z-server-generated--system']->system);
        self::assertFalse($byName['z-server-generated--system']->writable);

        self::assertTrue($byName['z-app-generated--contactsinteraction--recent']->appGenerated);
        self::assertFalse($byName['z-app-generated--contactsinteraction--recent']->writable);
    }

    public function testPropstatStatusFilteringAndNamespacePrefixVariants(): void
    {
        $client = $this->client([
            new Response(207, [], $this->fixture('principal.xml')),
            new Response(207, [], $this->fixture('home-set.xml')),
            new Response(207, [], $this->fixture('books-multistatus.xml')),
        ]);

        $books = $client->addressBooks();
        $byName = [];
        foreach ($books as $book) {
            $byName[$book->name] = $book;
        }

        // Home itself, plus one book each via d:, D: and a default DAV: namespace.
        self::assertCount(3, $books);

        self::assertSame('Kontakte', $byName['contacts']->displayName);
        self::assertTrue($byName['contacts']->writable);

        self::assertSame('Test-Adressbuch', $byName['test-adressbuch']->displayName);
        self::assertTrue($byName['test-adressbuch']->writable);

        self::assertSame('Default-NS', $byName['default-ns']->displayName);
        self::assertFalse($byName['default-ns']->writable);
    }

    public function testSearchSendsQueryWithLimitAndParsesCards(): void
    {
        $client = $this->client([new Response(207, [], $this->fixture('report.xml'))]);

        $cards = $client->search($this->contactsBook(), 'te<st', 7);

        $body = (string) $this->history[0]['request']->getBody();
        self::assertStringContainsString('nresults>7', $body);
        self::assertStringContainsString('te&lt;st', $body);
        self::assertStringNotContainsString('te<st', $body);

        self::assertCount(2, $cards);
        self::assertSame('"etag-anna-1"', $cards[0]->etag);
        self::assertStringContainsString('Anna Beispiel', $cards[0]->vcard);
        self::assertSame('"etag-bruno-1"', $cards[1]->etag);
        self::assertStringContainsString('Bruno Muster', $cards[1]->vcard);
    }

    public function testEmptySearchHasNoFilter(): void
    {
        $client = $this->client([new Response(207, [], $this->fixture('report.xml'))]);

        $client->search($this->contactsBook(), '', 5);

        $body = (string) $this->history[0]['request']->getBody();
        self::assertStringNotContainsString('prop-filter', $body);
        self::assertStringContainsString('nresults>5', $body);
    }

    public function testSearchLimitIsClampedToAtLeastOne(): void
    {
        $client = $this->client([new Response(207, [], $this->fixture('report.xml'))]);

        $client->search($this->contactsBook(), 'x', 0);

        $body = (string) $this->history[0]['request']->getBody();
        self::assertStringContainsString('nresults>1<', $body);
    }

    public function testSearchEscapesInvalidUtf8TextInsteadOfProducingEmptyMatch(): void
    {
        $client = $this->client([new Response(207, [], $this->fixture('report.xml'))]);

        $client->search($this->contactsBook(), "\xFF\xFEinvalid", 5);

        $body = (string) $this->history[0]['request']->getBody();
        self::assertStringNotContainsString('match-type="contains"></card:text-match>', $body);
        self::assertStringContainsString('invalid</card:text-match>', $body);
    }

    public function testPutNewUsesIfNoneMatchAndConflictThrows(): void
    {
        $book = $this->contactsBook();

        $client = $this->client([new Response(201, ['ETag' => '"new-etag-1"'])]);
        $card = $client->put($book, 'new.vcf', self::VCARD, null);
        self::assertSame('*', $this->history[0]['request']->getHeaderLine('If-None-Match'));
        self::assertFalse($this->history[0]['request']->hasHeader('If-Match'));
        self::assertSame('"new-etag-1"', $card->etag);

        $client = $this->client([new Response(412)]);
        $this->expectException(DavException::class);
        $this->expectExceptionMessage('precondition failed');
        $client->put($book, 'new.vcf', self::VCARD, null);
    }

    public function testPutExistingUsesIfMatch(): void
    {
        $book = $this->contactsBook();
        $client = $this->client([new Response(204, ['ETag' => '"updated-etag-1"'])]);

        $card = $client->put($book, 'anna.vcf', self::VCARD, '"old-etag-1"');

        self::assertSame('"old-etag-1"', $this->history[0]['request']->getHeaderLine('If-Match'));
        self::assertFalse($this->history[0]['request']->hasHeader('If-None-Match'));
        self::assertSame('"updated-etag-1"', $card->etag);
    }

    public function testPutWithoutEtagHeaderFallsBackToGet(): void
    {
        $book = $this->contactsBook();
        $client = $this->client([
            new Response(201, []),
            new Response(200, ['ETag' => '"fallback-etag-1"'], self::VCARD),
        ]);

        $card = $client->put($book, 'new.vcf', self::VCARD, null);

        self::assertCount(2, $this->history);
        self::assertSame('GET', $this->history[1]['request']->getMethod());
        self::assertSame('"fallback-etag-1"', $card->etag);
    }

    public function testPutAcceptsValidFilename(): void
    {
        $book = $this->contactsBook();
        $client = $this->client([new Response(201, ['ETag' => '"ok-etag-1"'])]);

        $card = $client->put($book, 'valid-name_1.vcf', self::VCARD, null);

        self::assertSame('"ok-etag-1"', $card->etag);
    }

    public function testPutRejectsPathTraversalFilename(): void
    {
        $client = $this->client([]);
        try {
            $client->put($this->contactsBook(), '../other/x.vcf', self::VCARD, null);
            self::fail('expected DavException');
        } catch (DavException $e) {
            self::assertStringContainsString('filename', $e->getMessage());
            self::assertCount(0, $this->history, 'must reject before sending any request');
        }
    }

    public function testPutRejectsFilenameWithSpace(): void
    {
        $client = $this->client([]);
        try {
            $client->put($this->contactsBook(), 'a b.vcf', self::VCARD, null);
            self::fail('expected DavException');
        } catch (DavException $e) {
            self::assertStringContainsString('filename', $e->getMessage());
            self::assertCount(0, $this->history, 'must reject before sending any request');
        }
    }

    public function testPutRejectsForeignOriginBookHref(): void
    {
        $book = new AddressBookRef('https://evil.example/addressbooks/x/', 'x', 'X', true, false, false);
        $client = $this->client([]);
        try {
            $client->put($book, 'valid.vcf', self::VCARD, null);
            self::fail('expected DavException');
        } catch (DavException $e) {
            self::assertStringContainsString('href', $e->getMessage());
            self::assertCount(0, $this->history, 'must reject before sending any request');
        }
    }

    public function testIndexRequestsOnlyUidAndEtagWithoutLimit(): void
    {
        $client = $this->client([new Response(207, [], $this->fixture('report.xml'))]);

        $cards = $client->index($this->contactsBook());

        $request = $this->history[0]['request'];
        $body = (string) $request->getBody();
        self::assertSame('REPORT', $request->getMethod());
        self::assertStringContainsString('<card:address-data><card:prop name="UID"/></card:address-data>', $body);
        self::assertStringNotContainsString('nresults', $body);
        self::assertStringNotContainsString('<card:filter', $body);
        self::assertCount(2, $cards);
        self::assertSame('"etag-anna-1"', $cards[0]->etag);
    }

    public function testPrincipalOutsideDavRootPathIsRejectedBeforeTheTokenIsSent(): void
    {
        $principal = \str_replace('/remote.php/dav/principals/users/nk/', '/index.php/apps/other/', $this->fixture('principal.xml'));
        $client = $this->client([
            new Response(207, [], $principal),
            new Response(207, [], $this->fixture('home-set.xml')),
            new Response(207, [], $this->fixture('books.xml')),
        ]);

        try {
            $client->addressBooks();
            self::fail('expected DavException');
        } catch (DavException) {
        }
        self::assertCount(1, $this->history);
    }

    public function testHrefWithDotSegmentsIsRejected(): void
    {
        $client = $this->client([new Response(200, ['ETag' => '"e"'], 'x'), new Response(200, ['ETag' => '"e"'], 'x'), new Response(200, ['ETag' => '"e"'], 'x')]);

        foreach (['/remote.php/dav/../../index.php', '/remote.php/dav/%2E%2E/index.php', self::DAV_ROOT . 'addressbooks/./x.vcf'] as $href) {
            try {
                $client->get($href);
                self::fail('expected DavException for ' . $href);
            } catch (DavException) {
            }
        }
        self::assertCount(0, $this->history);
    }

    public function testSearchRejectsBookOutsideDavRoot(): void
    {
        $client = $this->client([new Response(207, [], $this->fixture('report.xml'))]);
        $book = new AddressBookRef('https://nc.example.org/other/books/contacts/', 'contacts', 'Kontakte', true, false, false);

        try {
            $client->search($book, 'x', 5);
            self::fail('expected DavException');
        } catch (DavException) {
        }
        self::assertCount(0, $this->history);
    }

    public function testDeleteUsesIfMatch(): void
    {
        $client = $this->client([new Response(204)]);
        $client->delete(self::DAV_ROOT . 'addressbooks/users/nk/contacts/anna.vcf', '"etag-anna-1"');

        self::assertSame('DELETE', $this->history[0]['request']->getMethod());
        self::assertSame('"etag-anna-1"', $this->history[0]['request']->getHeaderLine('If-Match'));
    }

    public function testDeleteEtagRoundTripsByteForByteFromSearchResult(): void
    {
        $client = $this->client([new Response(207, [], $this->fixture('report.xml'))]);
        $cards = $client->search($this->contactsBook(), '', 10);
        $anna = $cards[0];
        self::assertSame('"etag-anna-1"', $anna->etag);

        $client = $this->client([new Response(204)]);
        $client->delete($anna->href, $anna->etag);

        self::assertSame('"etag-anna-1"', $this->history[0]['request']->getHeaderLine('If-Match'));
    }

    public function testDeleteWeakEtagRoundTripsUnchanged(): void
    {
        $client = $this->client([new Response(204)]);
        $client->delete(self::DAV_ROOT . 'addressbooks/users/nk/contacts/anna.vcf', 'W/"x"');

        self::assertSame('W/"x"', $this->history[0]['request']->getHeaderLine('If-Match'));
    }

    public function testDeleteWithRedirectStatusThrows(): void
    {
        $client = $this->client([new Response(302)]);
        $this->expectException(DavException::class);
        $client->delete(self::DAV_ROOT . 'addressbooks/users/nk/contacts/anna.vcf', '"etag-anna-1"');
    }

    public function testGetWithRedirectStatusThrows(): void
    {
        $client = $this->client([new Response(301)]);
        $this->expectException(DavException::class);
        $client->get(self::DAV_ROOT . 'addressbooks/users/nk/contacts/anna.vcf');
    }

    public function testUnauthorizedIsAuthFailed(): void
    {
        $client = $this->client([new Response(401)]);
        try {
            $client->addressBooks();
            self::fail('expected DavException');
        } catch (DavException $e) {
            self::assertTrue($e->authFailed());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function testTimeoutIsDavException(): void
    {
        $client = $this->client([
            new ConnectException('connection timed out', new Psr7Request('PROPFIND', self::DAV_ROOT)),
        ]);

        $this->expectException(DavException::class);
        $client->addressBooks();
    }

    public function testXxeEntityIsNotExpanded(): void
    {
        $client = $this->client([
            new Response(207, [], $this->fixture('principal.xml')),
            new Response(207, [], $this->fixture('home-set.xml')),
            new Response(207, [], $this->fixture('xxe.xml')),
        ]);

        try {
            $client->addressBooks();
            self::fail('expected DavException for doctype');
        } catch (DavException $e) {
            self::assertStringNotContainsString('root:', $e->getMessage());
            self::assertStringContainsString('doctype', $e->getMessage());
        }
    }

    public function testForeignHrefIsIgnored(): void
    {
        $client = $this->client([
            new Response(207, [], $this->fixture('principal.xml')),
            new Response(207, [], $this->fixture('home-set.xml')),
            new Response(207, [], $this->fixture('foreign-href.xml')),
        ]);

        $books = $client->addressBooks();

        self::assertCount(1, $books);
        self::assertSame('contacts', $books[0]->name);
    }

    public function testRelativeHrefResolvedAgainstRootOrigin(): void
    {
        $client = $this->client([
            new Response(207, [], $this->fixture('principal.xml')),
            new Response(207, [], $this->fixture('home-set.xml')),
            new Response(207, [], $this->fixture('books.xml')),
        ]);

        $books = $client->addressBooks();
        $contacts = null;
        foreach ($books as $book) {
            if ($book->name === 'contacts') {
                $contacts = $book;
            }
        }

        self::assertNotNull($contacts);
        self::assertSame('https://nc.example.org/remote.php/dav/addressbooks/users/nk/contacts/', $contacts->href);
    }
}
