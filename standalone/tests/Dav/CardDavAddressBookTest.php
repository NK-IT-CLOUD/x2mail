<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Tests\Dav;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use X2Mail\Engine\Providers\AddressBook\Classes\Contact;
use X2Mail\Standalone\Dav\AddressBookRef;
use X2Mail\Standalone\Dav\CardDavAddressBook;
use X2Mail\Standalone\Dav\CardRef;
use X2Mail\Standalone\Dav\DavContext;
use X2Mail\Standalone\Dav\DavException;
use X2Mail\Standalone\Log;

class CardDavAddressBookTest extends TestCase
{
    private const DAV_ROOT = 'https://nc.example.org/remote.php/dav/';

    /** @var list<string> */
    private array $logLines = [];

    private function log(): Log
    {
        $this->logLines = [];
        return new Log(function (string $line): void {
            $this->logLines[] = $line;
        });
    }

    private function ctx(bool $writablePersonal = true, bool $includeSystem = true): DavContext
    {
        return new DavContext(self::DAV_ROOT, 5, $writablePersonal, $includeSystem, 'user-key-1', '/tmp/x2w-cache');
    }

    private function personalBook(string $name = 'contacts'): AddressBookRef
    {
        return new AddressBookRef(self::DAV_ROOT . 'addressbooks/users/nk/' . $name . '/', $name, 'Kontakte', true, false, false);
    }

    private function systemBook(): AddressBookRef
    {
        return new AddressBookRef(self::DAV_ROOT . 'addressbooks/users/nk/z-server-generated--system/', 'z-server-generated--system', 'Systemadressbuch', false, true, false);
    }

    private function appBook(): AddressBookRef
    {
        return new AddressBookRef(self::DAV_ROOT . 'addressbooks/users/nk/z-app-generated--contactsinteraction--recent/', 'z-app-generated--contactsinteraction--recent', 'Zuletzt kontaktiert', false, false, true);
    }

    private function vcard(string $uid, string $fn, array $emails): string
    {
        $lines = ["BEGIN:VCARD", "VERSION:4.0", "UID:{$uid}", "FN:{$fn}"];
        foreach ($emails as $email) {
            $lines[] = "EMAIL:{$email}";
        }
        $lines[] = "END:VCARD";
        return \implode("\r\n", $lines) . "\r\n";
    }

    private function driver(FakeCardDavClient $client, DavContext $ctx, Log $log): CardDavAddressBook
    {
        return new CardDavAddressBook($client, $ctx, new ArrayAdapter(), $log);
    }

    public function testSuggestionsMergeDedupeOrderAndLimit(): void
    {
        $personal = $this->personalBook();
        $system = $this->systemBook();
        $app = $this->appBook();

        $client = new FakeCardDavClient();
        $client->books = [$personal, $system, $app];
        $client->cardsByBookHref[$personal->href] = [
            new CardRef($personal->href . 'anna.vcf', '"e1"', $this->vcard('uid-anna', 'Anna Beispiel', ['anna@example.org'])),
        ];
        $client->cardsByBookHref[$system->href] = [
            new CardRef($system->href . 'anna.vcf', '"e2"', $this->vcard('uid-anna-sys', 'Anna Beispiel', ['ANNA@example.org'])),
            new CardRef($system->href . 'bruno.vcf', '"e3"', $this->vcard('uid-bruno', 'Bruno Muster', ['bruno@example.org'])),
        ];
        $client->cardsByBookHref[$app->href] = [
            new CardRef($app->href . 'clara.vcf', '"e4"', $this->vcard('uid-clara', 'Clara Muster', ['clara@example.org'])),
        ];

        $driver = $this->driver($client, $this->ctx(), $this->log());
        $suggestions = $driver->GetSuggestions('an', 10);

        // anna@example.org appears in both the personal and the system book;
        // personal wins and the system duplicate is dropped.
        self::assertSame(
            [
                ['anna@example.org', 'Anna Beispiel'],
                ['bruno@example.org', 'Bruno Muster'],
                ['clara@example.org', 'Clara Muster'],
            ],
            $suggestions
        );
    }

    public function testShortQueryNoRequest(): void
    {
        $client = new FakeCardDavClient();
        $driver = $this->driver($client, $this->ctx(), $this->log());

        $suggestions = $driver->GetSuggestions('a', 10);

        self::assertSame([], $suggestions);
        self::assertSame(0, $client->addressBooksCallCount);
        self::assertSame([], $client->searchCalls);
    }

    public function testSystemBookExcludedWhenDisabled(): void
    {
        $personal = $this->personalBook();
        $system = $this->systemBook();

        $client = new FakeCardDavClient();
        $client->books = [$personal, $system];
        $client->cardsByBookHref[$personal->href] = [
            new CardRef($personal->href . 'anna.vcf', '"e1"', $this->vcard('uid-anna', 'Anna Beispiel', ['anna@example.org'])),
        ];
        $client->cardsByBookHref[$system->href] = [
            new CardRef($system->href . 'bruno.vcf', '"e2"', $this->vcard('uid-bruno', 'Bruno Muster', ['bruno@example.org'])),
        ];

        $driver = $this->driver($client, $this->ctx(includeSystem: false), $this->log());
        $suggestions = $driver->GetSuggestions('an', 10);

        self::assertSame([['anna@example.org', 'Anna Beispiel']], $suggestions);
        foreach ($client->searchCalls as $call) {
            self::assertNotSame($system->href, $call['book']->href);
        }
    }

    public function testContactsReadOnlyFlags(): void
    {
        $personal = $this->personalBook();
        $system = $this->systemBook();
        $app = $this->appBook();

        $client = new FakeCardDavClient();
        $client->books = [$personal, $system, $app];
        $client->cardsByBookHref[$personal->href] = [
            new CardRef($personal->href . 'anna.vcf', '"e1"', $this->vcard('uid-anna', 'Anna Beispiel', ['anna@example.org'])),
        ];
        $client->cardsByBookHref[$system->href] = [
            new CardRef($system->href . 'bruno.vcf', '"e2"', $this->vcard('uid-bruno', 'Bruno Muster', ['bruno@example.org'])),
        ];
        $client->cardsByBookHref[$app->href] = [
            new CardRef($app->href . 'clara.vcf', '"e3"', $this->vcard('uid-clara', 'Clara Muster', ['clara@example.org'])),
        ];

        $driver = $this->driver($client, $this->ctx(), $this->log());
        $count = 0;
        $contacts = $driver->GetContacts(0, 20, '', $count);

        $byUid = [];
        foreach ($contacts as $contact) {
            $byUid[$contact->IdContactStr] = $contact;
        }

        self::assertFalse($byUid['uid-anna']->ReadOnly);
        self::assertTrue($byUid['uid-bruno']->ReadOnly);
        self::assertTrue($byUid['uid-clara']->ReadOnly);
    }

    public function testOnlyTheDefaultBookIsEditable(): void
    {
        $personal = $this->personalBook();
        $other = $this->personalBook('test-adressbuch');

        $client = new FakeCardDavClient();
        $client->books = [$other, $personal];
        $client->cardsByBookHref[$personal->href] = [
            new CardRef($personal->href . 'anna.vcf', '"e1"', $this->vcard('uid-anna', 'Anna Beispiel', ['anna@example.org'])),
        ];
        $client->cardsByBookHref[$other->href] = [
            new CardRef($other->href . 'dora.vcf', '"e2"', $this->vcard('uid-dora', 'Dora Test', ['dora@example.org'])),
        ];

        $count = 0;
        $byUid = [];
        foreach ($this->driver($client, $this->ctx(), $this->log())->GetContacts(0, 20, '', $count) as $contact) {
            $byUid[$contact->IdContactStr] = $contact;
        }
        self::assertFalse($byUid['uid-anna']->ReadOnly);
        self::assertTrue($byUid['uid-dora']->ReadOnly, 'writable books other than the default one are not editable here');

        $count = 0;
        foreach ($this->driver($client, $this->ctx(writablePersonal: false), $this->log())->GetContacts(0, 20, '', $count) as $contact) {
            self::assertTrue($contact->ReadOnly, 'with writable = "none" nothing is editable');
        }
    }

    public function testIdLookupsUseTheUidIndexWithoutACap(): void
    {
        $personal = $this->personalBook();
        $client = new FakeCardDavClient();
        $client->books = [$personal];
        $client->cardsByBookHref[$personal->href] = [
            new CardRef($personal->href . 'anna.vcf', '"e1"', $this->vcard('uid-anna', 'Anna Beispiel', ['anna@example.org'])),
        ];
        $driver = $this->driver($client, $this->ctx(), $this->log());
        $annaId = (string) \abs(\crc32('uid-anna'));

        self::assertNotNull($driver->GetContactByID($annaId));
        self::assertTrue($driver->ContactSave($this->contactWithVcard('uid-anna', 'Anna Neu', ['anna@example.org'])));
        self::assertTrue($driver->DeleteContacts([$annaId]));

        self::assertSame([], $client->searchCalls, 'id lookups must not go through the capped search');
        self::assertCount(3, $client->indexCalls);
        self::assertSame('"e1"', $client->putCalls[0]['ifMatchEtag']);
        self::assertSame($personal->href . 'anna.vcf', $client->deleteCalls[0]['href']);
    }

    public function testContactIdIsCrc32OfUid(): void
    {
        $personal = $this->personalBook();
        $client = new FakeCardDavClient();
        $client->books = [$personal];
        $client->cardsByBookHref[$personal->href] = [
            new CardRef($personal->href . 'anna.vcf', '"e1"', $this->vcard('uid-anna', 'Anna Beispiel', ['anna@example.org'])),
        ];

        $driver = $this->driver($client, $this->ctx(), $this->log());
        $count = 0;
        $contacts = $driver->GetContacts(0, 20, '', $count);

        self::assertCount(1, $contacts);
        self::assertSame((string) \abs(\crc32('uid-anna')), $contacts[0]->id);
    }

    public function testSaveNewContactPutsIntoPersonalBookWithIfNoneMatch(): void
    {
        $personal = $this->personalBook();
        $client = new FakeCardDavClient();
        $client->books = [$personal];
        $client->cardsByBookHref[$personal->href] = [];

        $driver = $this->driver($client, $this->ctx(), $this->log());

        $contact = new Contact();
        $vcard = \Sabre\VObject\Reader::read($this->vcard('uid-new', 'New Person', ['new@example.org']));
        \assert($vcard instanceof \Sabre\VObject\Component\VCard);
        $contact->setVCard($vcard);

        $ok = $driver->ContactSave($contact);

        self::assertTrue($ok);
        self::assertCount(1, $client->putCalls);
        self::assertSame($personal->href, $client->putCalls[0]['book']->href);
        self::assertSame('uid-new.vcf', $client->putCalls[0]['filename']);
        self::assertNull($client->putCalls[0]['ifMatchEtag']);
    }

    public function testSaveExistingUsesIfMatch(): void
    {
        $personal = $this->personalBook();
        $client = new FakeCardDavClient();
        $client->books = [$personal];
        $client->cardsByBookHref[$personal->href] = [
            new CardRef($personal->href . 'legacy-filename.vcf', '"old-etag"', $this->vcard('uid-anna', 'Anna Beispiel', ['anna@example.org'])),
        ];

        $driver = $this->driver($client, $this->ctx(), $this->log());

        $contact = new Contact();
        $vcard = \Sabre\VObject\Reader::read($this->vcard('uid-anna', 'Anna Neu', ['anna@example.org']));
        \assert($vcard instanceof \Sabre\VObject\Component\VCard);
        $contact->setVCard($vcard);

        $ok = $driver->ContactSave($contact);

        self::assertTrue($ok);
        self::assertCount(1, $client->putCalls);
        self::assertSame('legacy-filename.vcf', $client->putCalls[0]['filename']);
        self::assertSame('"old-etag"', $client->putCalls[0]['ifMatchEtag']);
    }

    public function testSaveNotAllowedWhenWritableNone(): void
    {
        $personal = $this->personalBook();
        $client = new FakeCardDavClient();
        $client->books = [$personal];

        $driver = $this->driver($client, $this->ctx(writablePersonal: false), $this->log());

        $contact = new Contact();
        $vcard = \Sabre\VObject\Reader::read($this->vcard('uid-new', 'New Person', ['new@example.org']));
        \assert($vcard instanceof \Sabre\VObject\Component\VCard);
        $contact->setVCard($vcard);

        $ok = $driver->ContactSave($contact);

        self::assertFalse($ok);
        self::assertSame([], $client->putCalls);
    }

    public function testDeleteOnlyInPersonalBook(): void
    {
        $personal = $this->personalBook();
        $system = $this->systemBook();

        $client = new FakeCardDavClient();
        $client->books = [$personal, $system];
        $client->cardsByBookHref[$personal->href] = [
            new CardRef($personal->href . 'anna.vcf', '"e1"', $this->vcard('uid-anna', 'Anna Beispiel', ['anna@example.org'])),
        ];
        $client->cardsByBookHref[$system->href] = [
            new CardRef($system->href . 'bruno.vcf', '"e2"', $this->vcard('uid-bruno', 'Bruno Muster', ['bruno@example.org'])),
        ];

        $driver = $this->driver($client, $this->ctx(), $this->log());

        $annaId = (string) \abs(\crc32('uid-anna'));
        $brunoId = (string) \abs(\crc32('uid-bruno'));

        $ok = $driver->DeleteContacts([$annaId, $brunoId]);

        self::assertFalse($ok);
        self::assertCount(1, $client->deleteCalls);
        self::assertSame($personal->href . 'anna.vcf', $client->deleteCalls[0]['href']);
        self::assertSame('"e1"', $client->deleteCalls[0]['etag']);
    }

    public function testAuthFailureBlocksForFiveMinutesAndLogsOnce(): void
    {
        $client = new FakeCardDavClient();
        $client->addressBooksException = DavException::authFailure();

        $log = $this->log();
        $driver = $this->driver($client, $this->ctx(), $log);

        $first = $driver->GetSuggestions('an', 5);
        $second = $driver->GetSuggestions('an', 5);

        self::assertSame([], $first);
        self::assertSame([], $second);
        self::assertSame(1, $client->addressBooksCallCount);
        self::assertCount(1, $this->logLines);
        self::assertStringContainsString('carddav:', $this->logLines[0]);
    }

    public function testBrokenVcardSkipped(): void
    {
        $personal = $this->personalBook();
        $client = new FakeCardDavClient();
        $client->books = [$personal];
        $client->cardsByBookHref[$personal->href] = [
            new CardRef($personal->href . 'broken.vcf', '"e1"', "NOT A VCARD AT ALL"),
            new CardRef($personal->href . 'anna.vcf', '"e2"', $this->vcard('uid-anna', 'Anna Beispiel', ['anna@example.org'])),
        ];

        $driver = $this->driver($client, $this->ctx(), $this->log());
        $count = 0;
        $contacts = $driver->GetContacts(0, 20, '', $count);

        self::assertCount(1, $contacts);
        self::assertSame('uid-anna', $contacts[0]->IdContactStr);
    }

    public function testBookListCached(): void
    {
        $personal = $this->personalBook();
        $client = new FakeCardDavClient();
        $client->books = [$personal];
        $client->cardsByBookHref[$personal->href] = [];

        $driver = $this->driver($client, $this->ctx(), $this->log());

        $driver->GetSuggestions('an', 5);
        $driver->GetSuggestions('bo', 5);

        self::assertSame(1, $client->addressBooksCallCount);
    }

    public function testContactsListCappedAt1000(): void
    {
        $personal = $this->personalBook();
        $client = new FakeCardDavClient();
        $client->books = [$personal];
        $client->cardsByBookHref[$personal->href] = [];

        $driver = $this->driver($client, $this->ctx(), $this->log());
        $count = 0;
        $driver->GetContacts(0, 5, '', $count);

        self::assertCount(1, $client->searchCalls);
        self::assertSame(1000, $client->searchCalls[0]['limit']);
    }

    private function contactWithVcard(string $uid, string $fn, array $emails): Contact
    {
        $contact = new Contact();
        $vcard = \Sabre\VObject\Reader::read($this->vcard($uid, $fn, $emails));
        \assert($vcard instanceof \Sabre\VObject\Component\VCard);
        $contact->setVCard($vcard);
        return $contact;
    }

    // --- C1: ContactSave / DeleteContacts must never leak a DavException, and must respect the block marker ---

    public function testContactSaveGenericFailureReturnsFalseAndLogsOnce(): void
    {
        $client = new FakeCardDavClient();
        $client->addressBooksException = DavException::httpStatus(500);

        $log = $this->log();
        $driver = $this->driver($client, $this->ctx(), $log);

        $ok = $driver->ContactSave($this->contactWithVcard('uid-new', 'New Person', ['new@example.org']));

        self::assertFalse($ok);
        self::assertCount(1, $this->logLines);
        self::assertSame(1, $client->addressBooksCallCount);
        self::assertSame([], $client->putCalls);
    }

    public function testContactSaveAuthFailureBlocksAndLogsOnce(): void
    {
        $client = new FakeCardDavClient();
        $client->addressBooksException = DavException::authFailure();

        $log = $this->log();
        $driver = $this->driver($client, $this->ctx(), $log);
        $contact = $this->contactWithVcard('uid-new', 'New Person', ['new@example.org']);

        $ok = $driver->ContactSave($contact);
        self::assertFalse($ok);
        self::assertCount(1, $this->logLines);
        self::assertFalse($driver->IsSupported());

        // Second attempt within the block window: no further client call, no second log line.
        $ok2 = $driver->ContactSave($contact);
        self::assertFalse($ok2);
        self::assertSame(1, $client->addressBooksCallCount);
        self::assertCount(1, $this->logLines);
    }

    public function testDeleteContactsGenericFailureReturnsFalseAndLogsOnce(): void
    {
        $client = new FakeCardDavClient();
        $client->addressBooksException = DavException::httpStatus(500);

        $log = $this->log();
        $driver = $this->driver($client, $this->ctx(), $log);

        $ok = $driver->DeleteContacts(['1']);

        self::assertFalse($ok);
        self::assertCount(1, $this->logLines);
        self::assertSame([], $client->deleteCalls);
    }

    public function testDeleteContactsAuthFailureBlocksAndLogsOnce(): void
    {
        $client = new FakeCardDavClient();
        $client->addressBooksException = DavException::authFailure();

        $log = $this->log();
        $driver = $this->driver($client, $this->ctx(), $log);

        $ok = $driver->DeleteContacts(['1']);
        self::assertFalse($ok);
        self::assertCount(1, $this->logLines);

        $ok2 = $driver->DeleteContacts(['1']);
        self::assertFalse($ok2);
        self::assertSame(1, $client->addressBooksCallCount);
        self::assertCount(1, $this->logLines);
    }

    // --- I3: 412 must not escape either ---

    public function testContactSavePreconditionFailedReturnsFalseAndLogsOnce(): void
    {
        $personal = $this->personalBook();
        $client = new FakeCardDavClient();
        $client->books = [$personal];
        $client->cardsByBookHref[$personal->href] = [];
        $client->putException = DavException::preconditionFailed();

        $log = $this->log();
        $driver = $this->driver($client, $this->ctx(), $log);

        $ok = $driver->ContactSave($this->contactWithVcard('uid-new', 'New Person', ['new@example.org']));

        self::assertFalse($ok);
        self::assertCount(1, $this->logLines);
    }

    // --- I2: per-book isolation for reads ---

    public function testGetSuggestionsContinuesAfterOneBookFails(): void
    {
        $personal = $this->personalBook();
        $system = $this->systemBook();
        $app = $this->appBook();

        $client = new FakeCardDavClient();
        $client->books = [$personal, $system, $app];
        $client->cardsByBookHref[$personal->href] = [
            new CardRef($personal->href . 'anna.vcf', '"e1"', $this->vcard('uid-anna', 'Anna Beispiel', ['anna@example.org'])),
        ];
        $client->cardsByBookHref[$system->href] = [
            new CardRef($system->href . 'bruno.vcf', '"e2"', $this->vcard('uid-bruno', 'Bruno Muster', ['bruno@example.org'])),
        ];
        $client->searchExceptionByBookHref[$app->href] = DavException::httpStatus(500);

        $log = $this->log();
        $driver = $this->driver($client, $this->ctx(), $log);

        $suggestions = $driver->GetSuggestions('an', 10);

        self::assertSame(
            [
                ['anna@example.org', 'Anna Beispiel'],
                ['bruno@example.org', 'Bruno Muster'],
            ],
            $suggestions
        );
        self::assertCount(1, $this->logLines);
    }

    public function testGetSuggestionsAuthFailureOnSecondBookStopsEarly(): void
    {
        $personal = $this->personalBook();
        $system = $this->systemBook();
        $app = $this->appBook();

        $client = new FakeCardDavClient();
        $client->books = [$personal, $system, $app];
        $client->cardsByBookHref[$personal->href] = [
            new CardRef($personal->href . 'anna.vcf', '"e1"', $this->vcard('uid-anna', 'Anna Beispiel', ['anna@example.org'])),
        ];
        $client->searchExceptionByBookHref[$system->href] = DavException::authFailure();

        $log = $this->log();
        $driver = $this->driver($client, $this->ctx(), $log);

        $suggestions = $driver->GetSuggestions('an', 10);

        self::assertSame([['anna@example.org', 'Anna Beispiel']], $suggestions);
        self::assertCount(1, $this->logLines);
        self::assertFalse($driver->IsSupported());
        foreach ($client->searchCalls as $call) {
            self::assertNotSame($app->href, $call['book']->href);
        }
    }

    public function testGetContactsContinuesAfterOneBookFails(): void
    {
        $personal = $this->personalBook();
        $system = $this->systemBook();

        $client = new FakeCardDavClient();
        $client->books = [$personal, $system];
        $client->cardsByBookHref[$personal->href] = [
            new CardRef($personal->href . 'anna.vcf', '"e1"', $this->vcard('uid-anna', 'Anna Beispiel', ['anna@example.org'])),
        ];
        $client->searchExceptionByBookHref[$system->href] = DavException::httpStatus(500);

        $log = $this->log();
        $driver = $this->driver($client, $this->ctx(), $log);

        $count = 0;
        $contacts = $driver->GetContacts(0, 20, '', $count);

        self::assertCount(1, $contacts);
        self::assertSame('uid-anna', $contacts[0]->IdContactStr);
        self::assertCount(1, $this->logLines);
    }

    public function testGetContactByEmailContinuesAfterOneBookFails(): void
    {
        $personal = $this->personalBook();
        $system = $this->systemBook();

        $client = new FakeCardDavClient();
        $client->books = [$personal, $system];
        $client->searchExceptionByBookHref[$personal->href] = DavException::httpStatus(500);
        $client->cardsByBookHref[$system->href] = [
            new CardRef($system->href . 'bruno.vcf', '"e2"', $this->vcard('uid-bruno', 'Bruno Muster', ['bruno@example.org'])),
        ];

        $log = $this->log();
        $driver = $this->driver($client, $this->ctx(), $log);

        $contact = $driver->GetContactByEmail('bruno@example.org');

        self::assertNotNull($contact);
        self::assertSame('uid-bruno', $contact->IdContactStr);
        self::assertCount(1, $this->logLines);
    }

    public function testGetContactByIDContinuesAfterOneBookFails(): void
    {
        $personal = $this->personalBook();
        $system = $this->systemBook();

        $client = new FakeCardDavClient();
        $client->books = [$personal, $system];
        $client->searchExceptionByBookHref[$personal->href] = DavException::httpStatus(500);
        $client->cardsByBookHref[$system->href] = [
            new CardRef($system->href . 'bruno.vcf', '"e2"', $this->vcard('uid-bruno', 'Bruno Muster', ['bruno@example.org'])),
        ];

        $log = $this->log();
        $driver = $this->driver($client, $this->ctx(), $log);

        $contact = $driver->GetContactByID('uid-bruno', true);

        self::assertNotNull($contact);
        self::assertSame('uid-bruno', $contact->IdContactStr);
        self::assertCount(1, $this->logLines);
    }

    // --- M5/M6: GetContactByID matches regardless of $bIsStrID and prefers the personal book on a tie ---

    public function testGetContactByIDMatchesRegardlessOfIsStrIDFlag(): void
    {
        $personal = $this->personalBook();
        $client = new FakeCardDavClient();
        $client->books = [$personal];
        $client->cardsByBookHref[$personal->href] = [
            new CardRef($personal->href . 'anna.vcf', '"e1"', $this->vcard('uid-anna', 'Anna Beispiel', ['anna@example.org'])),
        ];

        $driver = $this->driver($client, $this->ctx(), $this->log());

        $byUidDespiteFlag = $driver->GetContactByID('uid-anna', false);
        self::assertNotNull($byUidDespiteFlag);
        self::assertSame('uid-anna', $byUidDespiteFlag->IdContactStr);

        $crc32Id = (string) \abs(\crc32('uid-anna'));
        $byCrcDespiteFlag = $driver->GetContactByID($crc32Id, true);
        self::assertNotNull($byCrcDespiteFlag);
        self::assertSame('uid-anna', $byCrcDespiteFlag->IdContactStr);
    }

    public function testGetContactByIDSearchesBooksInPersonalFirstOrder(): void
    {
        $personal = $this->personalBook();
        $system = $this->systemBook();
        $app = $this->appBook();

        $client = new FakeCardDavClient();
        // Deliberately out of order in the client's list.
        $client->books = [$system, $app, $personal];
        $client->cardsByBookHref[$personal->href] = [
            new CardRef($personal->href . 'anna.vcf', '"e1"', $this->vcard('uid-anna', 'Anna Beispiel', ['anna@example.org'])),
        ];

        $driver = $this->driver($client, $this->ctx(), $this->log());
        $contact = $driver->GetContactByID('uid-anna', true);

        self::assertNotNull($contact);
        $order = \array_map(static fn (AddressBookRef $book): string => $book->href, $client->indexCalls);
        self::assertSame([$personal->href], $order, 'personal book must be searched first and the match stops the search there');
    }

    // --- M9: suggestion display name falls back to NICKNAME when FN is absent ---

    public function testSuggestionDisplayNameFallsBackToNickname(): void
    {
        $personal = $this->personalBook();
        $client = new FakeCardDavClient();
        $client->books = [$personal];
        $vcardText = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:uid-nn\r\nNICKNAME:Nico\r\nEMAIL:nico@example.org\r\nEND:VCARD\r\n";
        $client->cardsByBookHref[$personal->href] = [new CardRef($personal->href . 'nn.vcf', '"e1"', $vcardText)];

        $driver = $this->driver($client, $this->ctx(), $this->log());
        $suggestions = $driver->GetSuggestions('nic', 10);

        self::assertSame([['nico@example.org', 'Nico']], $suggestions);
    }

    // --- M10: a successful save copies the server's ETag back onto the Contact ---

    public function testContactSaveCopiesReturnedEtagIntoContact(): void
    {
        $personal = $this->personalBook();
        $client = new FakeCardDavClient();
        $client->books = [$personal];
        $client->cardsByBookHref[$personal->href] = [];
        $client->putResult = new CardRef($personal->href . 'uid-new.vcf', '"server-etag-1"', '');

        $driver = $this->driver($client, $this->ctx(), $this->log());
        $contact = $this->contactWithVcard('uid-new', 'New Person', ['new@example.org']);

        $ok = $driver->ContactSave($contact);

        self::assertTrue($ok);
        self::assertSame('"server-etag-1"', $contact->Etag);
    }

    // --- Fix round 2: an unreadable vCard (from another client) must never throw into the engine ---

    /**
     * A mismatched BEGIN/END pair is genuinely rejected by Sabre's own parser,
     * not something our code special-cases — confirmed directly against Reader::read.
     */
    private function brokenVcardThatSabreRejects(): string
    {
        $text = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:uid-broken\r\nEND:VEVENT\r\n";
        try {
            \Sabre\VObject\Reader::read($text);
            self::fail('fixture is not actually broken: Reader::read did not throw');
        } catch (\Throwable) {
            // expected — this is what makes it a useful fixture.
        }
        return $text;
    }

    public function testGetContactsSkipsUnreadableCardAndLogsOnce(): void
    {
        $personal = $this->personalBook();
        $client = new FakeCardDavClient();
        $client->books = [$personal];
        $client->cardsByBookHref[$personal->href] = [
            new CardRef($personal->href . 'anna.vcf', '"e1"', $this->vcard('uid-anna', 'Anna Beispiel', ['anna@example.org'])),
            new CardRef($personal->href . 'broken.vcf', '"e2"', $this->brokenVcardThatSabreRejects()),
            new CardRef($personal->href . 'bruno.vcf', '"e3"', $this->vcard('uid-bruno', 'Bruno Muster', ['bruno@example.org'])),
        ];

        $log = $this->log();
        $driver = $this->driver($client, $this->ctx(), $log);

        $count = 0;
        $contacts = $driver->GetContacts(0, 20, '', $count);

        $uids = \array_map(static fn (Contact $c): string => $c->IdContactStr, $contacts);
        self::assertSame(['uid-anna', 'uid-bruno'], $uids);
        self::assertCount(1, $this->logLines);
        self::assertStringContainsString('skipped 1 unreadable', $this->logLines[0]);
    }

    public function testGetSuggestionsSkipsUnreadableCardAndLogsOnce(): void
    {
        $personal = $this->personalBook();
        $client = new FakeCardDavClient();
        $client->books = [$personal];
        $client->cardsByBookHref[$personal->href] = [
            new CardRef($personal->href . 'anna.vcf', '"e1"', $this->vcard('uid-anna', 'Anna Beispiel', ['anna@example.org'])),
            new CardRef($personal->href . 'broken.vcf', '"e2"', $this->brokenVcardThatSabreRejects()),
            new CardRef($personal->href . 'bruno.vcf', '"e3"', $this->vcard('uid-bruno', 'Bruno Muster', ['bruno@example.org'])),
        ];

        $log = $this->log();
        $driver = $this->driver($client, $this->ctx(), $log);

        $suggestions = $driver->GetSuggestions('an', 10);

        self::assertSame(
            [
                ['anna@example.org', 'Anna Beispiel'],
                ['bruno@example.org', 'Bruno Muster'],
            ],
            $suggestions
        );
        self::assertCount(1, $this->logLines);
        self::assertStringContainsString('skipped 1 unreadable', $this->logLines[0]);
    }

    public function testContactSavePutThrowingGenericExceptionReturnsFalseAndLogsOnce(): void
    {
        $personal = $this->personalBook();
        $client = new FakeCardDavClient();
        $client->books = [$personal];
        $client->cardsByBookHref[$personal->href] = [];
        $client->putGenericException = new \RuntimeException('serialize() blew up');

        $log = $this->log();
        $driver = $this->driver($client, $this->ctx(), $log);

        $ok = $driver->ContactSave($this->contactWithVcard('uid-new', 'New Person', ['new@example.org']));

        self::assertFalse($ok);
        self::assertCount(1, $this->logLines);
    }
}
