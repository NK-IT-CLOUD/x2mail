<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Tests;

use PHPUnit\Framework\TestCase;
use X2Mail\Standalone\Auth\Identity;
use X2Mail\Standalone\Dav\DavContext;
use X2Mail\Standalone\Runtime;
use X2Mail\Standalone\StandaloneBridge;

class StandaloneBridgeTest extends TestCase
{
    protected function tearDown(): void
    {
        Runtime::reset();
    }

    public function testWithIdentity(): void
    {
        $b = new StandaloneBridge(new Identity('anna@kunde-a.at', 'anna', 'kunde-a', 'AT', []), 'sess-1');
        self::assertTrue($b->isSsoLogin());
        self::assertSame('anna@kunde-a.at', $b->ssoEmail());
        self::assertSame('anna', $b->ssoUid());
        self::assertSame('sess-1', $b->sessionSeed());
    }

    public function testWithoutIdentity(): void
    {
        $b = new StandaloneBridge(null, '');
        self::assertFalse($b->isSsoLogin());
        self::assertNull($b->ssoEmail());
        self::assertNull($b->ssoUid());
        self::assertNull($b->sessionSeed());
    }

    public function testRuntimeHoldsIdentity(): void
    {
        self::assertNull(Runtime::identity());
        $id = new Identity('a@b.c', 'a', null, 'AT', []);
        Runtime::set($id);
        self::assertSame($id, Runtime::identity());
    }

    public function testRuntimeHoldsDavContext(): void
    {
        self::assertNull(Runtime::dav());
        $ctx = new DavContext('https://nc.example.org/remote.php/dav/', 5, true, true, 'user-key', '/tmp/x2w-cache');
        Runtime::setDav($ctx);
        self::assertSame($ctx, Runtime::dav());
    }

    public function testRuntimeHoldsPrimaryColor(): void
    {
        self::assertNull(Runtime::primaryColor());
        Runtime::setPrimaryColor('#aa3300');
        self::assertSame('#aa3300', Runtime::primaryColor());
        Runtime::reset();
        self::assertNull(Runtime::primaryColor());
    }

    public function testRuntimeResetClearsDavContext(): void
    {
        Runtime::setDav(new DavContext('https://nc.example.org/remote.php/dav/', 5, true, true, 'user-key', '/tmp/x2w-cache'));
        Runtime::reset();
        self::assertNull(Runtime::dav());
    }
}
