<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Tests\Session;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use X2Mail\Standalone\Session\PhpSession;

class PhpSessionTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPhpSessionUsableAfterDestroy(): void
    {
        $session = PhpSession::start();
        $session->set('a', 1);
        $oldId = $session->id();

        $session->destroy();

        $session->set('b', 2);
        self::assertSame(2, $session->get('b'));
        self::assertNull($session->get('a'));
        self::assertNotSame('', $session->id());
        self::assertNotSame($oldId, $session->id());
        self::assertSame(PHP_SESSION_ACTIVE, \session_status());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPhpSessionCookieParams(): void
    {
        $session = PhpSession::start();
        $params = \session_get_cookie_params();

        self::assertSame('', $params['domain']);
        self::assertTrue($params['secure']);
        self::assertTrue($params['httponly']);
        self::assertSame('Lax', $params['samesite']);
        self::assertSame('/', $params['path']);
        self::assertSame(0, $params['lifetime']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRegenerateThrowsWithoutActiveSession(): void
    {
        $s = new PhpSession();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('session id could not be regenerated');
        $s->regenerate();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDestroyThrowsWithoutActiveSession(): void
    {
        $s = new PhpSession();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('session id could not be regenerated');
        $s->destroy();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLazySessionDoesNotStartUntilUsed(): void
    {
        $session = PhpSession::lazy();
        self::assertSame(PHP_SESSION_NONE, \session_status());

        $session->get('x');

        self::assertSame(PHP_SESSION_ACTIVE, \session_status());
    }
}
