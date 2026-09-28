<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Tests\Dav;

use PHPUnit\Framework\TestCase;
use X2Mail\Standalone\Auth\Identity;
use X2Mail\Standalone\Dav\AddressBookFactory;
use X2Mail\Standalone\Dav\CardDavAddressBook;
use X2Mail\Standalone\Dav\DavContext;
use X2Mail\Standalone\Log;
use X2Mail\Standalone\Runtime;

class AddressBookFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        Runtime::reset();
    }

    public function testNullWithoutIdentity(): void
    {
        Runtime::setDav(new DavContext('https://nc.example.org/remote.php/dav/', 5, true, true, 'user-key', \sys_get_temp_dir()));

        self::assertNull(AddressBookFactory::fromRuntime(new Log(static function (): void {
        })));
    }

    public function testNullWithoutDavContext(): void
    {
        Runtime::set(new Identity('anna@kunde-a.at', 'anna', 'kunde-a', 'AT', []));

        self::assertNull(AddressBookFactory::fromRuntime(new Log(static function (): void {
        })));
    }

    public function testDriverBuiltWithContext(): void
    {
        Runtime::set(new Identity('anna@kunde-a.at', 'anna', 'kunde-a', 'AT', []));
        Runtime::setDav(new DavContext('https://nc.example.org/remote.php/dav/', 5, true, true, 'user-key', \sys_get_temp_dir()));

        $book = AddressBookFactory::fromRuntime(new Log(static function (): void {
        }));

        self::assertInstanceOf(CardDavAddressBook::class, $book);
    }
}
