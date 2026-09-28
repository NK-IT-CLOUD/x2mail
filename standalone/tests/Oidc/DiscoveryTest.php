<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Tests\Oidc;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use X2Mail\Standalone\Oidc\Discovery;
use X2Mail\Standalone\Oidc\OidcException;

class DiscoveryTest extends TestCase
{
    private const ISS = 'https://id.example.org/realms/central';

    private string $cache;

    protected function setUp(): void
    {
        $this->cache = \sys_get_temp_dir() . '/x2w-disc-' . \bin2hex(\random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @\unlink($this->cache);
    }

    /** @param array<string, mixed> $doc */
    private function discovery(MockHandler $mock): Discovery
    {
        return new Discovery(new Client(['handler' => HandlerStack::create($mock)]), new HttpFactory(), $this->cache);
    }

    /** @return array<string, mixed> */
    private function doc(array $override = []): array
    {
        return $override + [
            'issuer' => self::ISS,
            'authorization_endpoint' => self::ISS . '/protocol/openid-connect/auth',
            'token_endpoint' => self::ISS . '/protocol/openid-connect/token',
            'userinfo_endpoint' => self::ISS . '/protocol/openid-connect/userinfo',
            'jwks_uri' => self::ISS . '/protocol/openid-connect/certs',
            'end_session_endpoint' => self::ISS . '/protocol/openid-connect/logout',
        ];
    }

    public function testLoadsAndCaches(): void
    {
        $mock = new MockHandler([new Response(200, [], (string) \json_encode($this->doc()))]);
        $e = $this->discovery($mock)->load(self::ISS);
        self::assertSame(self::ISS . '/protocol/openid-connect/token', $e->token);
        self::assertSame(self::ISS . '/protocol/openid-connect/logout', $e->endSession);

        // Second load is served from the cache file: the mock has no response left.
        $again = $this->discovery(new MockHandler([]))->load(self::ISS);
        self::assertSame($e->jwksUri, $again->jwksUri);
    }

    public function testIssuerMismatchRejected(): void
    {
        $mock = new MockHandler([new Response(200, [], (string) \json_encode($this->doc(['issuer' => 'https://evil.example.org'])))]);
        $this->expectException(OidcException::class);
        $this->expectExceptionMessage('issuer');
        $this->discovery($mock)->load(self::ISS);
    }

    public function testNonHttpsEndpointRejected(): void
    {
        $mock = new MockHandler([new Response(200, [], (string) \json_encode($this->doc(['token_endpoint' => 'http://id.example.org/token'])))]);
        $this->expectException(OidcException::class);
        $this->expectExceptionMessage('token_endpoint');
        $this->discovery($mock)->load(self::ISS);
    }

    public function testHttpErrorRejected(): void
    {
        $this->expectException(OidcException::class);
        $this->discovery(new MockHandler([new Response(503)]))->load(self::ISS);
    }

    public function testEndSessionOptional(): void
    {
        $doc = $this->doc();
        unset($doc['end_session_endpoint']);
        self::assertNull($this->discovery(new MockHandler([new Response(200, [], (string) \json_encode($doc))]))->load(self::ISS)->endSession);
    }

    public function testStaleCacheFallsBackWhenFetchFails(): void
    {
        \file_put_contents($this->cache, (string) \json_encode($this->doc()));
        \touch($this->cache, \time() - 7200); // older than the default 3600s TTL

        $mock = new MockHandler([new Response(503)]);
        $e = $this->discovery($mock)->load(self::ISS);

        self::assertSame(self::ISS . '/protocol/openid-connect/token', $e->token);
    }

    public function testStaleCacheFallsBackWhenFetchThrows(): void
    {
        \file_put_contents($this->cache, (string) \json_encode($this->doc()));
        \touch($this->cache, \time() - 7200);

        $mock = new MockHandler([new \GuzzleHttp\Exception\ConnectException('boom', new \GuzzleHttp\Psr7\Request('GET', self::ISS))]);
        $e = $this->discovery($mock)->load(self::ISS);

        self::assertSame(self::ISS . '/protocol/openid-connect/userinfo', $e->userinfo);
    }

    public function testMissingCacheStillThrowsWhenFetchFails(): void
    {
        $this->expectException(OidcException::class);
        $this->discovery(new MockHandler([new Response(503)]))->load(self::ISS);
    }

    public function testCacheWithChangedIssuerIsIgnoredAndFetchedFresh(): void
    {
        \file_put_contents($this->cache, (string) \json_encode($this->doc(['issuer' => 'https://old-issuer.example.org'])));
        \touch($this->cache, \time()); // fresh mtime, but wrong issuer

        $mock = new MockHandler([new Response(200, [], (string) \json_encode($this->doc()))]);
        $e = $this->discovery($mock)->load(self::ISS);

        self::assertSame(self::ISS . '/protocol/openid-connect/token', $e->token);
        $rewritten = \json_decode((string) \file_get_contents($this->cache), true);
        self::assertSame(self::ISS, $rewritten['issuer']);
    }
}
