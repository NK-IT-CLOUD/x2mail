<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use X2Mail\Standalone\Http\Route;
use X2Mail\Standalone\Http\Router;

class RouterTest extends TestCase
{
    /** @return iterable<string, array{string, Route}> */
    public static function uris(): iterable
    {
        yield 'root' => ['/', Route::Engine];
        yield 'engine json' => ['/?/Json/&q[]=/0/', Route::Engine];
        yield 'login' => ['/oidc/login', Route::Login];
        yield 'callback with query' => ['/oidc/callback?code=x&state=y', Route::Callback];
        yield 'logout' => ['/oidc/logout', Route::Logout];
        yield 'health' => ['/healthz', Route::Health];
        yield 'admin panel' => ['/?admin', Route::Blocked];
        yield 'admin panel later param' => ['/?x=1&admin', Route::Blocked];
        yield 'engine index direct' => ['/index.php', Route::Engine];
        yield 'other php' => ['/x2mail/v/current/setup.php', Route::Blocked];
        yield 'unknown oidc' => ['/oidc/other', Route::Blocked];
        yield 'path traversal' => ['/oidc/../healthz', Route::Blocked];
    }

    #[DataProvider('uris')]
    public function testRoute(string $uri, Route $expected): void
    {
        self::assertSame($expected, (new Router())->route($uri));
    }
}
