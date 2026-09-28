<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Tests\Oidc;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use X2Mail\Standalone\Config\Config;
use X2Mail\Standalone\Oidc\Endpoints;
use X2Mail\Standalone\Oidc\IdpRequestException;
use X2Mail\Standalone\Oidc\LoginFlowException;
use X2Mail\Standalone\Oidc\LoginRequest;
use X2Mail\Standalone\Oidc\OidcClient;
use X2Mail\Standalone\Oidc\OidcException;
use X2Mail\Standalone\Oidc\TokenSet;
use X2Mail\Standalone\Oidc\TokenValidator;
use X2Mail\Standalone\Tests\Support\TestKeys;

class OidcClientTest extends TestCase
{
    private const ISS = 'https://id.example.org/realms/central';

    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private string $secretFile;

    protected function setUp(): void
    {
        $this->secretFile = \tempnam(\sys_get_temp_dir(), 'x2w-sec');
        \file_put_contents($this->secretFile, 's3cret');
    }

    protected function tearDown(): void
    {
        \unlink($this->secretFile);
    }

    private function config(): Config
    {
        return Config::fromArray([
            'webmail' => ['base_url' => 'https://webmail.example.org'],
            'oidc' => ['issuer' => self::ISS, 'client_id' => 'webmail', 'client_secret_file' => $this->secretFile],
            'mail' => ['host' => 'mail.example.org'],
            'rules' => ['mail_user' => ['claim' => 'groups', 'any_of' => ['mail']]],
            'access' => ['login_requires' => 'mail_user'],
        ]);
    }

    /** @param list<Response> $responses */
    private function client(array $responses): OidcClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $endpoints = new Endpoints(self::ISS . '/auth', self::ISS . '/token', self::ISS . '/userinfo', self::ISS . '/certs', self::ISS . '/logout');
        return new OidcClient($this->config(), $endpoints, new TokenValidator(TestKeys::keySet(), self::ISS), new Client(['handler' => $stack]));
    }

    private function accessToken(int $ttl = 300): string
    {
        return TestKeys::issue(['iss' => self::ISS, 'exp' => \time() + $ttl, 'groups' => ['mail'], 'email' => 'a@example.org']);
    }

    private function tokenResponse(string $nonce, ?string $refresh = 'RT-1', bool $withId = true): Response
    {
        $body = ['access_token' => $this->accessToken(), 'expires_in' => 300, 'token_type' => 'Bearer'];
        if ($refresh !== null) {
            $body['refresh_token'] = $refresh;
        }
        if ($withId) {
            $body['id_token'] = TestKeys::issue(['iss' => self::ISS, 'aud' => 'webmail', 'exp' => \time() + 300, 'nonce' => $nonce, 'sub' => 'u-1']);
        }
        return new Response(200, ['Content-Type' => 'application/json'], (string) \json_encode($body));
    }

    /** @param array<string, mixed> $claims */
    private function refreshResponseWithIdToken(array $claims): Response
    {
        $now = \time();
        $body = [
            'access_token' => $this->accessToken(),
            'expires_in' => 300,
            'token_type' => 'Bearer',
            'refresh_token' => 'RT-2',
            'id_token' => TestKeys::issue(\array_merge(['iss' => self::ISS, 'exp' => $now + 300, 'iat' => $now], $claims)),
        ];
        return new Response(200, ['Content-Type' => 'application/json'], (string) \json_encode($body));
    }

    public function testBeginLoginBuildsPkceUrlWithNonceAndConfiguredRedirect(): void
    {
        $r = $this->client([])->beginLogin();
        \parse_str((string) \parse_url($r['url'], \PHP_URL_QUERY), $q);

        self::assertStringStartsWith(self::ISS . '/auth?', $r['url']);
        self::assertSame('https://webmail.example.org/oidc/callback', $q['redirect_uri']);
        self::assertSame('S256', $q['code_challenge_method']);
        self::assertSame('openid email profile organization', $q['scope']);
        self::assertSame($r['request']->nonce, $q['nonce']);
        self::assertSame($r['request']->state, $q['state']);
        self::assertGreaterThanOrEqual(43, \strlen($r['request']->pkceVerifier));
    }

    public function testCompleteLoginExchangesCodeWithVerifier(): void
    {
        $client = $this->client([]);
        $pending = $client->beginLogin()['request'];
        $client = $this->client([$this->tokenResponse($pending->nonce)]);

        $tokens = $client->completeLogin('C-1', $pending->state, $pending, \time());

        self::assertSame('RT-1', $tokens->refreshToken);
        self::assertSame(['mail'], $tokens->claims['groups']);
        \parse_str((string) $this->history[0]['request']->getBody(), $form);
        self::assertSame($pending->pkceVerifier, $form['code_verifier']);
        self::assertSame('C-1', $form['code']);
    }

    public function testCompleteLoginRejectsStateMismatch(): void
    {
        $pending = $this->client([])->beginLogin()['request'];
        $this->expectException(LoginFlowException::class);
        $this->expectExceptionMessage('state');
        $this->client([])->completeLogin('C-1', 'forged', $pending, \time());
    }

    public function testStateMismatchIsLoginFlowExceptionNotIdpRequestException(): void
    {
        $pending = $this->client([])->beginLogin()['request'];
        try {
            $this->client([])->completeLogin('C-1', 'forged', $pending, \time());
            self::fail('expected LoginFlowException');
        } catch (OidcException $e) {
            self::assertInstanceOf(LoginFlowException::class, $e);
            self::assertNotInstanceOf(IdpRequestException::class, $e);
        }
    }

    public function testTokenEndpointFailureThrowsIdpRequestException(): void
    {
        $pending = $this->client([])->beginLogin()['request'];
        $this->expectException(IdpRequestException::class);
        $this->client([new Response(500, [], 'boom')])->completeLogin('C-1', $pending->state, $pending, \time());
    }

    public function testCompleteLoginRejectsStalePendingRequest(): void
    {
        $pending = $this->client([])->beginLogin()['request'];
        $this->expectException(LoginFlowException::class);
        $this->expectExceptionMessage('expired');
        $this->client([])->completeLogin('C-1', $pending->state, $pending, \time() + 601);
    }

    public function testCompleteLoginRejectsWrongNonce(): void
    {
        $pending = $this->client([])->beginLogin()['request'];
        $this->expectException(OidcException::class);
        $this->client([$this->tokenResponse('other-nonce')])->completeLogin('C-1', $pending->state, $pending, \time());
    }

    public function testCompleteLoginRejectsMissingIdToken(): void
    {
        $pending = $this->client([])->beginLogin()['request'];
        $this->expectException(OidcException::class);
        $this->expectExceptionMessage('id_token');
        $this->client([$this->tokenResponse($pending->nonce, 'RT', false)])->completeLogin('C-1', $pending->state, $pending, \time());
    }

    public function testRefreshKeepsIdTokenAndRefreshTokenWhenNotRotated(): void
    {
        $old = new TokenSet('AT-old', 'RT-1', 'ID-old', \time() + 10, []);
        $new = $this->client([$this->tokenResponse('x', null, false)])->refresh($old);

        self::assertSame('ID-old', $new->idToken);
        self::assertSame('RT-1', $new->refreshToken);
        self::assertSame(['mail'], $new->claims['groups']);
        \parse_str((string) $this->history[0]['request']->getBody(), $form);
        self::assertSame('refresh_token', $form['grant_type']);
    }

    public function testRefreshWithoutRefreshTokenFails(): void
    {
        $this->expectException(OidcException::class);
        $this->client([])->refresh(new TokenSet('AT', null, 'ID', \time() + 10, []));
    }

    public function testRefreshRejectsRotatedIdTokenForAnotherSubject(): void
    {
        $old = new TokenSet('AT', 'RT-1', 'ID-OLD', \time() + 10, ['sub' => 'user-1']);
        $response = $this->refreshResponseWithIdToken(['sub' => 'user-2', 'aud' => 'webmail']);

        $this->expectException(OidcException::class);
        $this->client([$response])->refresh($old);
    }

    public function testRefreshRejectsRotatedIdTokenForAnotherAudience(): void
    {
        $old = new TokenSet('AT', 'RT-1', 'ID-OLD', \time() + 10, ['sub' => 'user-1']);
        $response = $this->refreshResponseWithIdToken(['sub' => 'user-1', 'aud' => 'other-client']);

        $this->expectException(OidcException::class);
        $this->client([$response])->refresh($old);
    }

    public function testRefreshAcceptsValidRotatedIdToken(): void
    {
        $old = new TokenSet('AT', 'RT-1', 'ID-OLD', \time() + 10, ['sub' => 'user-1']);
        $response = $this->refreshResponseWithIdToken(['sub' => 'user-1', 'aud' => 'webmail']);

        $new = $this->client([$response])->refresh($old);

        self::assertNotSame('ID-OLD', $new->idToken);
    }

    public function testRefreshRejectsRotatedIdTokenWithMultiAudienceAndWrongAzp(): void
    {
        $old = new TokenSet('AT', 'RT-1', 'ID-OLD', \time() + 10, ['sub' => 'user-1']);
        $response = $this->refreshResponseWithIdToken(['sub' => 'user-1', 'aud' => ['webmail', 'other'], 'azp' => 'other']);

        $this->expectException(OidcException::class);
        $this->client([$response])->refresh($old);
    }

    public function testRefreshAcceptsRotatedIdTokenWithMultiAudienceAndCorrectAzp(): void
    {
        $old = new TokenSet('AT', 'RT-1', 'ID-OLD', \time() + 10, ['sub' => 'user-1']);
        $response = $this->refreshResponseWithIdToken(['sub' => 'user-1', 'aud' => ['webmail', 'other'], 'azp' => 'webmail']);

        $new = $this->client([$response])->refresh($old);

        self::assertNotSame('ID-OLD', $new->idToken);
    }

    public function testRefreshIdpErrorBecomesOidcException(): void
    {
        $this->expectException(OidcException::class);
        $this->client([new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}')])
            ->refresh(new TokenSet('AT', 'RT', 'ID', \time() + 10, []));
    }

    public function testRefreshInvalidGrantIsPlainOidcExceptionNotIdpRequestException(): void
    {
        try {
            $this->client([new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}')])
                ->refresh(new TokenSet('AT', 'RT', 'ID', \time() + 10, []));
            self::fail('expected OidcException');
        } catch (OidcException $e) {
            self::assertNotInstanceOf(IdpRequestException::class, $e);
        }
    }

    public function testCodeExchangeInvalidGrantBecomesLoginFlowException(): void
    {
        $pending = $this->client([])->beginLogin()['request'];
        $this->expectException(LoginFlowException::class);
        $this->client([new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}')])
            ->completeLogin('C-1', $pending->state, $pending, \time());
    }

    public function testCodeExchangeInvalidGrantIsNotIdpRequestException(): void
    {
        $pending = $this->client([])->beginLogin()['request'];
        try {
            $this->client([new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}')])
                ->completeLogin('C-1', $pending->state, $pending, \time());
            self::fail('expected LoginFlowException');
        } catch (OidcException $e) {
            self::assertNotInstanceOf(IdpRequestException::class, $e);
        }
    }

    public function testLogoutUrl(): void
    {
        $url = (string) $this->client([])->logoutUrl(new TokenSet('AT', 'RT', 'ID-1', \time(), []));
        \parse_str((string) \parse_url($url, \PHP_URL_QUERY), $q);
        self::assertStringStartsWith(self::ISS . '/logout?', $url);
        self::assertSame('ID-1', $q['id_token_hint']);
        self::assertSame('https://webmail.example.org/', $q['post_logout_redirect_uri']);
        self::assertSame('webmail', $q['client_id']);
    }

    public function testLogoutUrlForHintWithHint(): void
    {
        $url = (string) $this->client([])->logoutUrlForHint('ID-1');
        \parse_str((string) \parse_url($url, \PHP_URL_QUERY), $q);
        self::assertStringStartsWith(self::ISS . '/logout?', $url);
        self::assertSame('ID-1', $q['id_token_hint']);
        self::assertSame('https://webmail.example.org/', $q['post_logout_redirect_uri']);
        self::assertSame('webmail', $q['client_id']);
    }

    public function testLogoutUrlForHintWithoutHint(): void
    {
        $url = (string) $this->client([])->logoutUrlForHint(null);
        \parse_str((string) \parse_url($url, \PHP_URL_QUERY), $q);
        self::assertStringStartsWith(self::ISS . '/logout?', $url);
        self::assertArrayNotHasKey('id_token_hint', $q);
        self::assertSame('https://webmail.example.org/', $q['post_logout_redirect_uri']);
        self::assertSame('webmail', $q['client_id']);
    }

    public function testTokenSetRoundTripAndExpiry(): void
    {
        $t = new TokenSet('AT', 'RT', 'ID', 1000, ['a' => 1]);
        self::assertEquals($t, TokenSet::fromArray($t->toArray()));
        self::assertTrue($t->expiresWithin(60, 950));
        self::assertFalse($t->expiresWithin(60, 900));
        self::assertNull(TokenSet::fromArray(['accessToken' => 'x']));
        self::assertNull(LoginRequest::fromArray([]));
    }
}
