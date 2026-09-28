<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Tests\Auth;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use X2Mail\Standalone\Access\RuleEvaluator;
use X2Mail\Standalone\Access\TenantResolver;
use X2Mail\Standalone\Auth\SessionAuth;
use X2Mail\Standalone\Config\Config;
use X2Mail\Standalone\Log;
use X2Mail\Standalone\Oidc\Endpoints;
use X2Mail\Standalone\Oidc\OidcClient;
use X2Mail\Standalone\Oidc\TokenSet;
use X2Mail\Standalone\Oidc\TokenValidator;
use X2Mail\Standalone\Session\ArraySession;
use X2Mail\Standalone\Tests\Support\TestKeys;

class SessionAuthTest extends TestCase
{
    private const ISS = 'https://id.example.org/realms/central';

    private string $secretFile;

    private ArraySession $session;

    /** @var list<string> */
    private array $logLines = [];

    protected function setUp(): void
    {
        $this->secretFile = \tempnam(\sys_get_temp_dir(), 'x2w-sec');
        \file_put_contents($this->secretFile, 's3cret');
        $this->session = new ArraySession();
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
            'rules' => [
                'mail_user' => ['claim' => 'realm_access.roles', 'any_of' => ['mailxyz']],
                'nc_user' => ['claim' => 'realm_access.roles', 'any_of' => ['abz']],
            ],
            'access' => ['login_requires' => 'mail_user'],
            'dav' => ['contacts' => ['type' => 'carddav', 'requires' => 'nc_user', 'url_template' => 'https://{tenant}.example.cloud/remote.php/dav/']],
        ]);
    }

    /** @param list<Response> $responses */
    private function auth(array $responses = []): SessionAuth
    {
        $config = $this->config();
        $endpoints = new Endpoints(self::ISS . '/auth', self::ISS . '/token', self::ISS . '/userinfo', self::ISS . '/certs', self::ISS . '/logout');
        $http = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
        $oidc = new OidcClient($config, $endpoints, new TokenValidator(TestKeys::keySet(), self::ISS), $http);
        $log = new Log(function (string $line): void {
            $this->logLines[] = $line;
        });
        return new SessionAuth($config, new RuleEvaluator(), new TenantResolver('organization', $config->oidc->tenantRegex), $oidc, $this->session, $log);
    }

    /** @param array<string, mixed> $extra */
    private function access(array $roles, array $extra = [], int $ttl = 300): string
    {
        return TestKeys::issue($extra + [
            'iss' => self::ISS, 'exp' => \time() + $ttl, 'email' => 'anna@kunde-a.at',
            'preferred_username' => 'anna', 'sub' => 'u-1', 'organization' => ['kunde-a'],
            'realm_access' => ['roles' => $roles],
        ]);
    }

    private function tokenResponse(string $access, string $nonce, ?string $refresh = 'RT-1', int $expiresIn = 300): Response
    {
        $body = ['access_token' => $access, 'expires_in' => $expiresIn, 'token_type' => 'Bearer',
            'id_token' => TestKeys::issue(['iss' => self::ISS, 'aud' => 'webmail', 'exp' => \time() + 300, 'nonce' => $nonce, 'sub' => 'u-1'])];
        if ($refresh !== null) {
            $body['refresh_token'] = $refresh;
        }
        return new Response(200, ['Content-Type' => 'application/json'], (string) \json_encode($body));
    }

    /** Runs startLogin + finishLogin with the given access token claims. */
    private function login(array $roles, array $extra = [], int $ttl = 300): \X2Mail\Standalone\Auth\AuthResult
    {
        $this->auth()->startLogin();
        $pending = $this->session->get('x2w.login');
        return $this->auth([$this->tokenResponse($this->access($roles, $extra, $ttl), $pending['nonce'], 'RT-1', $ttl)])
            ->finishLogin('C-1', $pending['state']);
    }

    public function testStartLoginStoresPendingRequest(): void
    {
        $url = $this->auth()->startLogin();
        self::assertStringStartsWith(self::ISS . '/auth?', $url);
        self::assertIsArray($this->session->get('x2w.login'));
    }

    public function testEstablishRegeneratesSession(): void
    {
        $result = $this->login(['mailxyz']);
        self::assertTrue($result->ok);
        self::assertSame(1, $this->session->regenerations);
        self::assertNull($this->session->get('x2w.login'), 'pending request is single-use');
    }

    public function testIdentityCarriesEmailTenantAndFeatures(): void
    {
        $this->login(['mailxyz', 'abz']);
        $id = $this->auth()->current(\time());

        self::assertNotNull($id);
        self::assertSame('anna@kunde-a.at', $id->email);
        self::assertSame('anna', $id->uid);
        self::assertSame('kunde-a', $id->tenant);
        self::assertSame(['contacts' => true], $id->features);
    }

    public function testFeatureOffWithoutRole(): void
    {
        $this->login(['mailxyz']);
        self::assertSame(['contacts' => false], $this->auth()->current(\time())?->features);
    }

    public function testInvalidTenantKeepsLoginButDisablesTenantFeatures(): void
    {
        $this->login(['mailxyz', 'abz'], ['organization' => ['a', 'b']]);
        $id = $this->auth()->current(\time());
        self::assertNotNull($id, 'login must survive a bad organization claim');
        self::assertNull($id->tenant);
        self::assertSame(['contacts' => false], $id->features);
        self::assertStringContainsString('exactly one organization', \implode("\n", $this->logLines));
    }

    public function testLoginWithoutMailRoleIsForbidden(): void
    {
        $result = $this->login(['abz']);
        self::assertFalse($result->ok);
        self::assertSame('login rule not met', $result->reason);
        self::assertNull($this->auth()->current(\time()));
    }

    public function testLoginWithoutEmailIsForbidden(): void
    {
        $result = $this->login(['mailxyz'], ['email' => 'no-at-sign']);
        self::assertFalse($result->ok);
    }

    public function testTokenEndpointFailureIsRetryable(): void
    {
        $this->auth()->startLogin();
        $pending = $this->session->get('x2w.login');
        $result = $this->auth([new Response(500, [], 'boom')])->finishLogin('C-1', $pending['state']);
        self::assertFalse($result->ok);
        self::assertSame(\X2Mail\Standalone\Auth\AuthOutcome::Unavailable, $result->outcome);
        self::assertSame('identity provider unavailable', $result->reason);
    }

    public function testForgedStateIsForbiddenWithoutException(): void
    {
        $this->auth()->startLogin();
        $result = $this->auth()->finishLogin('C-1', 'forged');
        self::assertFalse($result->ok);
    }

    public function testCallbackWithoutPendingRequest(): void
    {
        self::assertFalse($this->auth()->finishLogin('C-1', 'x')->ok);
    }

    public function testNoRefreshWhileTokenIsFresh(): void
    {
        $this->login(['mailxyz']);
        // A refresh would need a mocked response; none is queued, so any refresh would fail.
        self::assertNotNull($this->auth()->current(\time()));
    }

    public function testRefreshWhenExpiringAndSecondCallSeesNewToken(): void
    {
        $this->login(['mailxyz']);
        $before = $this->auth()->current(\time())?->accessToken;

        $soon = \time() + 250; // 50 s left of 300 → below the 60 s window
        $fresh = $this->access(['mailxyz'], [], 600);
        $first = $this->auth([$this->tokenResponse($fresh, 'x', 'RT-2', 600)])->current($soon);
        $second = $this->auth([])->current($soon); // no response queued: must not refresh again

        self::assertNotSame($before, $first?->accessToken);
        self::assertSame($first?->accessToken, $second?->accessToken);
    }

    public function testRefreshFailureEndsSession(): void
    {
        $this->login(['mailxyz']);
        $id = $this->auth([new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}')])
            ->current(\time() + 250);
        self::assertNull($id);
        self::assertNull($this->session->get('x2w.tokens'));
    }

    public function testTransientRefreshFailureKeepsSessionWhileTokenStillValid(): void
    {
        $this->login(['mailxyz']);
        $before = $this->auth()->current(\time())?->accessToken;

        // 30 s left of a 300 s token: below the 60 s refresh window, so a
        // refresh is attempted, but the token endpoint is unreachable.
        $soon = \time() + 270;
        $id = $this->auth([new Response(503, [], 'boom')])->current($soon);

        self::assertNotNull($id, 'a transient IdP outage must not end a still-valid session');
        self::assertSame($before, $id->accessToken);
        self::assertIsArray($this->session->get('x2w.tokens'), 'session must survive');
        self::assertStringContainsString('refresh failed, keeping session until token expiry', \implode("\n", $this->logLines));
    }

    public function testTransientRefreshFailureEndsSessionOnceTokenHasExpired(): void
    {
        $this->login(['mailxyz']);
        $expired = \time() + 301; // past the 300 s token lifetime

        $id = $this->auth([new Response(503, [], 'boom')])->current($expired);

        self::assertNull($id);
        self::assertNull($this->session->get('x2w.tokens'));
    }

    public function testDefinitiveRefreshFailureEndsSessionEvenWithTimeLeft(): void
    {
        $this->login(['mailxyz']);
        $soon = \time() + 270;

        $id = $this->auth([new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}')])
            ->current($soon);

        self::assertNull($id);
        self::assertNull($this->session->get('x2w.tokens'));
    }

    public function testRefreshWithoutLoginRoleEndsSession(): void
    {
        $this->login(['mailxyz']);
        $revoked = $this->access(['abz'], [], 600);
        self::assertNull($this->auth([$this->tokenResponse($revoked, 'x')])->current(\time() + 250));
        self::assertNull($this->session->get('x2w.tokens'));
    }

    public function testLogoutReturnsIdpUrlAndDestroysSession(): void
    {
        $this->login(['mailxyz']);
        $url = $this->auth()->logout();
        self::assertStringStartsWith(self::ISS . '/logout?', $url);
        self::assertNull($this->session->get('x2w.tokens'));
    }

    public function testLogoutWithoutAnythingStillGoesToIdp(): void
    {
        $url = $this->auth()->logout();
        self::assertStringStartsWith(self::ISS . '/logout?', $url);
        \parse_str((string) \parse_url($url, \PHP_URL_QUERY), $q);
        self::assertArrayNotHasKey('id_token_hint', $q);
        self::assertSame('webmail', $q['client_id']);
        self::assertSame('https://webmail.example.org/', $q['post_logout_redirect_uri']);
    }

    public function testLogoutAfterRefusedLoginEndsIdpSession(): void
    {
        $this->auth()->startLogin();
        $pending = $this->session->get('x2w.login');
        $idToken = TestKeys::issue(['iss' => self::ISS, 'aud' => 'webmail', 'exp' => \time() + 300, 'nonce' => $pending['nonce'], 'sub' => 'u-1']);
        $access = $this->access(['abz']); // no mail role -> Denied
        $body = ['access_token' => $access, 'expires_in' => 300, 'token_type' => 'Bearer', 'refresh_token' => 'RT-1', 'id_token' => $idToken];
        $response = new Response(200, ['Content-Type' => 'application/json'], (string) \json_encode($body));
        $result = $this->auth([$response])->finishLogin('C-1', $pending['state']);
        self::assertFalse($result->ok);
        self::assertSame(\X2Mail\Standalone\Auth\AuthOutcome::Denied, $result->outcome);

        $url = $this->auth()->logout();
        self::assertStringStartsWith(self::ISS . '/logout?', $url);
        \parse_str((string) \parse_url($url, \PHP_URL_QUERY), $q);
        self::assertSame($idToken, $q['id_token_hint']);
    }

    public function testSuccessfulLoginClearsLogoutHint(): void
    {
        // Refused login stores the ID token as logout hint
        $this->auth()->startLogin();
        $pending = $this->session->get('x2w.login');
        $idToken = TestKeys::issue(['iss' => self::ISS, 'aud' => 'webmail', 'exp' => \time() + 300, 'nonce' => $pending['nonce'], 'sub' => 'u-1']);
        $access = $this->access(['abz']); // no mail role -> Denied
        $body = ['access_token' => $access, 'expires_in' => 300, 'token_type' => 'Bearer', 'refresh_token' => 'RT-1', 'id_token' => $idToken];
        $response = new Response(200, ['Content-Type' => 'application/json'], (string) \json_encode($body));
        $this->auth([$response])->finishLogin('C-1', $pending['state']);
        self::assertSame($idToken, $this->session->get('x2w.logout_hint'));

        // Successful login should clear the stale logout hint
        $this->auth()->startLogin();
        $pending2 = $this->session->get('x2w.login');
        $result = $this->login(['mailxyz']);
        self::assertTrue($result->ok);
        self::assertNull($this->session->get('x2w.logout_hint'));
    }

    public function testTokensNeverLogged(): void
    {
        $this->login(['mailxyz'], ['organization' => ['a', 'b']]);
        $token = (string) $this->auth()->current(\time())?->accessToken;
        self::assertStringNotContainsString($token, \implode("\n", $this->logLines));
    }

    public function testCorruptSessionDataIsNoIdentity(): void
    {
        $this->session->set('x2w.tokens', ['accessToken' => 'x']);
        self::assertNull($this->auth()->current(\time()));
    }

    public function testFreshTokenSetIsStoredAsArray(): void
    {
        $this->login(['mailxyz']);
        self::assertInstanceOf(TokenSet::class, TokenSet::fromArray($this->session->get('x2w.tokens')));
    }

    public function testStoredClaimsWithoutEmailAreNoIdentity(): void
    {
        $this->login(['mailxyz']);
        // Manually set corrupt claims (missing email)
        $stored = $this->session->get('x2w.tokens');
        $stored['claims'] = ['iss' => self::ISS, 'sub' => 'u-1'];
        $this->session->set('x2w.tokens', $stored);

        // current() should detect corrupt claims and destroy session
        self::assertNull($this->auth()->current(\time()));
        self::assertNull($this->session->get('x2w.tokens'));
    }

    public function testStoredClaimsWithArrayEmailAreNoIdentity(): void
    {
        $this->login(['mailxyz']);
        // Manually set corrupt claims (array email)
        $stored = $this->session->get('x2w.tokens');
        $stored['claims'] = ['email' => ['a', 'b'], 'sub' => 'u-1', 'realm_access' => ['roles' => ['mailxyz']]];
        $this->session->set('x2w.tokens', $stored);

        // current() should detect corrupt claims and destroy session
        self::assertNull($this->auth()->current(\time()));
        self::assertNull($this->session->get('x2w.tokens'));
    }

    public function testSessionUsableAfterDestroy(): void
    {
        $this->login(['mailxyz']);
        $url = $this->auth()->logout();
        self::assertStringStartsWith(self::ISS . '/logout?', $url);

        // After destroy, session should still be usable for new login
        $url2 = $this->auth()->startLogin();
        self::assertStringStartsWith(self::ISS . '/auth?', $url2);
        self::assertIsArray($this->session->get('x2w.login'));
    }

    public function testNoTenantWarningWithoutTenantDav(): void
    {
        // Config with no DAV services needing {tenant}
        $config = Config::fromArray([
            'webmail' => ['base_url' => 'https://webmail.example.org'],
            'oidc' => ['issuer' => self::ISS, 'client_id' => 'webmail', 'client_secret_file' => $this->secretFile],
            'mail' => ['host' => 'mail.example.org'],
            'rules' => [
                'mail_user' => ['claim' => 'realm_access.roles', 'any_of' => ['mailxyz']],
            ],
            'access' => ['login_requires' => 'mail_user'],
            'dav' => [],
        ]);

        $endpoints = new Endpoints(self::ISS . '/auth', self::ISS . '/token', self::ISS . '/userinfo', self::ISS . '/certs', self::ISS . '/logout');
        $http = new Client(['handler' => HandlerStack::create(new MockHandler([]))]);
        $oidc = new OidcClient($config, $endpoints, new TokenValidator(TestKeys::keySet(), self::ISS), $http);
        $sessionLocal = new ArraySession();
        $log = new Log(function (string $line): void {
            $this->logLines[] = $line;
        });
        $auth = new SessionAuth($config, new RuleEvaluator(), new TenantResolver('organization', $config->oidc->tenantRegex), $oidc, $sessionLocal, $log);

        // Login with invalid tenant
        $auth->startLogin();
        $pending = $sessionLocal->get('x2w.login');
        $result = new SessionAuth($config, new RuleEvaluator(), new TenantResolver('organization', $config->oidc->tenantRegex),
            new OidcClient($config, $endpoints, new TokenValidator(TestKeys::keySet(), self::ISS),
                new Client(['handler' => HandlerStack::create(new MockHandler([$this->tokenResponse($this->access(['mailxyz'], ['organization' => ['a', 'b']]), $pending['nonce'])]))])),
            $sessionLocal, $log)
            ->finishLogin('C-1', $pending['state']);

        // current() should not log tenant warning when no DAV service needs {tenant}
        $this->logLines = [];
        $auth->current(\time());
        $logOutput = \implode("\n", $this->logLines);
        self::assertStringNotContainsString('tenant', $logOutput);
    }

    public function testForgedStateIsExpiredNotDenied(): void
    {
        $this->auth()->startLogin();
        $result = $this->auth()->finishLogin('C-1', 'forged');
        self::assertFalse($result->ok);
        self::assertSame(\X2Mail\Standalone\Auth\AuthOutcome::Expired, $result->outcome);
    }

    public function testStalePendingRequestIsExpired(): void
    {
        $this->auth()->startLogin();
        $pending = $this->session->get('x2w.login');
        $pending['createdAt'] = \time() - 601;
        $this->session->set('x2w.login', $pending);
        $result = $this->auth()->finishLogin('C-1', $pending['state']);
        self::assertFalse($result->ok);
        self::assertSame(\X2Mail\Standalone\Auth\AuthOutcome::Expired, $result->outcome);
    }

    public function testShortTokenRefreshWindowIsHalfTheLifetime(): void
    {
        // 40 s lifetime -> effective refresh window is min(60, 20) = 20 s.
        $callCount = 0;
        $config = $this->config();
        $issued = \time();

        $closure = function () use (&$callCount, $config, $issued): OidcClient {
            $callCount++;
            $endpoints = new Endpoints(self::ISS . '/auth', self::ISS . '/token', self::ISS . '/userinfo', self::ISS . '/certs', self::ISS . '/logout');
            $fresh = TestKeys::issue(['iss' => self::ISS, 'exp' => $issued + 600, 'email' => 'anna@kunde-a.at', 'preferred_username' => 'anna', 'sub' => 'u-1', 'organization' => ['kunde-a'], 'realm_access' => ['roles' => ['mailxyz']]]);
            $id = TestKeys::issue(['iss' => self::ISS, 'aud' => 'webmail', 'exp' => $issued + 600, 'nonce' => 'x', 'sub' => 'u-1']);
            $response = new Response(200, ['Content-Type' => 'application/json'], (string) \json_encode(['access_token' => $fresh, 'id_token' => $id, 'refresh_token' => 'RT-2', 'expires_in' => 600, 'token_type' => 'Bearer']));
            return new OidcClient($config, $endpoints, new TokenValidator(TestKeys::keySet(), self::ISS), new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]));
        };

        $log = new Log(function (string $line): void {
            $this->logLines[] = $line;
        });
        $auth = new SessionAuth($config, new RuleEvaluator(), new TenantResolver('organization', $config->oidc->tenantRegex), $closure, $this->session, $log);

        $claims = ['iss' => self::ISS, 'exp' => $issued + 40, 'email' => 'anna@kunde-a.at', 'preferred_username' => 'anna', 'sub' => 'u-1', 'organization' => ['kunde-a'], 'realm_access' => ['roles' => ['mailxyz']]];
        $access = $this->access(['mailxyz'], [], 40);
        $this->session->set('x2w.tokens', (new TokenSet($access, 'RT', 'ID', $issued + 40, $claims))->toArray());
        $this->session->set('x2w.lifetime', 40);

        $noRefresh = $auth->current($issued + 15);
        self::assertNotNull($noRefresh);
        self::assertSame(0, $callCount, 'must not refresh 25 s before a 40 s token expires');

        $refreshed = $auth->current($issued + 25);
        self::assertNotNull($refreshed);
        self::assertSame(1, $callCount, 'must refresh 15 s before a 40 s token expires');
    }

    public function testRefusalLogNamesHashedSubject(): void
    {
        $result = $this->login(['abz'], ['sub' => 'u-1']);
        self::assertFalse($result->ok);

        $expectedTag = 'sub#' . \substr(\hash('sha256', 'u-1'), 0, 12);
        $log = \implode("\n", $this->logLines);
        self::assertStringContainsString($expectedTag, $log);
        self::assertStringNotContainsString('u-1', $log);
        self::assertStringNotContainsString('anna@kunde-a.at', $log);
    }

    public function testFreshSessionDoesNotBuildOidcClient(): void
    {
        $callCount = 0;
        $closure = function () use (&$callCount): OidcClient {
            $callCount++;
            $endpoints = new Endpoints(self::ISS . '/auth', self::ISS . '/token', self::ISS . '/userinfo', self::ISS . '/certs', self::ISS . '/logout');
            return new OidcClient($this->config(), $endpoints, new TokenValidator(TestKeys::keySet(), self::ISS), new Client(['handler' => HandlerStack::create(new MockHandler([]))]));
        };

        $config = $this->config();
        $log = new Log(function (string $line): void {
            $this->logLines[] = $line;
        });
        $auth = new SessionAuth($config, new RuleEvaluator(), new TenantResolver('organization', $config->oidc->tenantRegex), $closure, $this->session, $log);

        // Fresh session with valid token (far from expiry)
        $now = \time();
        $access = $this->access(['mailxyz'], [], 300);
        $this->session->set('x2w.tokens', (new TokenSet($access, 'RT', 'ID', $now + 300, ['iss' => self::ISS, 'exp' => $now + 300, 'email' => 'anna@kunde-a.at', 'preferred_username' => 'anna', 'sub' => 'u-1', 'organization' => ['kunde-a'], 'realm_access' => ['roles' => ['mailxyz']]]))->toArray());

        // current() should return identity without building OIDC client
        $id = $auth->current($now);
        self::assertNotNull($id);
        self::assertSame('anna@kunde-a.at', $id->email);
        self::assertSame(0, $callCount, 'OIDC client factory must not be called for fresh tokens');
    }

    public function testExpiringSessionBuildsOidcClientOnce(): void
    {
        $callCount = 0;
        $config = $this->config();
        $baseTime = \time();

        $closure = function () use (&$callCount, $config, $baseTime): OidcClient {
            $callCount++;
            $endpoints = new Endpoints(self::ISS . '/auth', self::ISS . '/token', self::ISS . '/userinfo', self::ISS . '/certs', self::ISS . '/logout');
            $fresh = TestKeys::issue(['iss' => self::ISS, 'exp' => $baseTime + 600, 'email' => 'anna@kunde-a.at', 'preferred_username' => 'anna', 'sub' => 'u-1', 'organization' => ['kunde-a'], 'realm_access' => ['roles' => ['mailxyz']]]);
            $id = TestKeys::issue(['iss' => self::ISS, 'aud' => 'webmail', 'exp' => $baseTime + 600, 'nonce' => 'x', 'sub' => 'u-1']);
            $response = new Response(200, ['Content-Type' => 'application/json'], (string) \json_encode(['access_token' => $fresh, 'id_token' => $id, 'refresh_token' => 'RT-2', 'expires_in' => 600, 'token_type' => 'Bearer']));
            return new OidcClient($config, $endpoints, new TokenValidator(TestKeys::keySet(), self::ISS), new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]));
        };

        $log = new Log(function (string $line): void {
            $this->logLines[] = $line;
        });
        $auth = new SessionAuth($config, new RuleEvaluator(), new TenantResolver('organization', $config->oidc->tenantRegex), $closure, $this->session, $log);

        // Session with token expiring soon (within refresh window)
        $now = $baseTime;
        $access = $this->access(['mailxyz']);
        $this->session->set('x2w.tokens', (new TokenSet($access, 'RT', 'ID', $now + 300, ['iss' => self::ISS, 'exp' => $now + 300, 'email' => 'anna@kunde-a.at', 'preferred_username' => 'anna', 'sub' => 'u-1', 'organization' => ['kunde-a'], 'realm_access' => ['roles' => ['mailxyz']]]))->toArray());

        // Call current() at time when token is within refresh window
        $soon = $now + 250; // 50 s left, below 60 s refresh window
        $id = $auth->current($soon);
        self::assertNotNull($id);
        self::assertSame(1, $callCount, 'OIDC factory must be called once for token refresh');

        // Second call to current() on same auth instance should reuse the factory
        $id2 = $auth->current($soon + 1); // still within the session, no more refreshes needed
        self::assertNotNull($id2);
        self::assertSame(1, $callCount, 'OIDC factory must only be called once, result cached');
    }

    public function testTinyStoredLifetimeFallsBackToDefaultWindow(): void
    {
        $callCount = 0;
        $config = $this->config();
        $baseTime = \time();

        $closure = function () use (&$callCount, $config, $baseTime): OidcClient {
            $callCount++;
            $endpoints = new Endpoints(self::ISS . '/auth', self::ISS . '/token', self::ISS . '/userinfo', self::ISS . '/certs', self::ISS . '/logout');
            $fresh = TestKeys::issue(['iss' => self::ISS, 'exp' => $baseTime + 600, 'email' => 'anna@kunde-a.at', 'preferred_username' => 'anna', 'sub' => 'u-1', 'organization' => ['kunde-a'], 'realm_access' => ['roles' => ['mailxyz']]]);
            $id = TestKeys::issue(['iss' => self::ISS, 'aud' => 'webmail', 'exp' => $baseTime + 600, 'nonce' => 'x', 'sub' => 'u-1']);
            $response = new Response(200, ['Content-Type' => 'application/json'], (string) \json_encode(['access_token' => $fresh, 'id_token' => $id, 'refresh_token' => 'RT-2', 'expires_in' => 600, 'token_type' => 'Bearer']));
            return new OidcClient($config, $endpoints, new TokenValidator(TestKeys::keySet(), self::ISS), new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]));
        };

        $log = new Log(function (string $line): void {
            $this->logLines[] = $line;
        });
        $auth = new SessionAuth($config, new RuleEvaluator(), new TenantResolver('organization', $config->oidc->tenantRegex), $closure, $this->session, $log);

        // Session with a token that has 150 s left and tiny stored lifetime (1)
        $access = $this->access(['mailxyz']);
        $this->session->set('x2w.tokens', (new TokenSet($access, 'RT', 'ID', $baseTime + 150, ['iss' => self::ISS, 'exp' => $baseTime + 150, 'email' => 'anna@kunde-a.at', 'preferred_username' => 'anna', 'sub' => 'u-1', 'organization' => ['kunde-a'], 'realm_access' => ['roles' => ['mailxyz']]]))->toArray());
        $this->session->set('x2w.lifetime', 1);

        // With lifetime=1, old code would use min(60, 0) = 0 window (refresh only after expiry)
        // New code should fallback to default 60
        // With 150 s left and window 60, no refresh yet (150 > 60)
        $stillFresh = $auth->current($baseTime);
        self::assertNotNull($stillFresh);
        self::assertSame(0, $callCount, 'tiny lifetime 1 should fallback to default window 60, no refresh yet');

        // At 50 s left (within the default 60 s window), refresh should happen
        $expiringIn50 = $auth->current($baseTime + 100);
        self::assertNotNull($expiringIn50);
        self::assertSame(1, $callCount, 'with 50 s left and window 60, refresh should happen');
    }

    public function testDeniedReLoginDropsEarlierSessionAndLogoutUsesHint(): void
    {
        // First: successful login with mail role
        $result1 = $this->login(['mailxyz']);
        self::assertTrue($result1->ok);
        self::assertIsArray($this->session->get('x2w.tokens'), 'first login should store tokens');

        // Second: re-login attempt without mail role (Denied)
        $this->auth()->startLogin();
        $pending = $this->session->get('x2w.login');
        $idToken2 = TestKeys::issue(['iss' => self::ISS, 'aud' => 'webmail', 'exp' => \time() + 300, 'nonce' => $pending['nonce'], 'sub' => 'u-1']);
        $access2 = $this->access(['abz']); // no mail role -> Denied
        $body = ['access_token' => $access2, 'expires_in' => 300, 'token_type' => 'Bearer', 'refresh_token' => 'RT-1', 'id_token' => $idToken2];
        $response = new Response(200, ['Content-Type' => 'application/json'], (string) \json_encode($body));

        $result2 = $this->auth([$response])->finishLogin('C-1', $pending['state']);
        self::assertFalse($result2->ok);
        self::assertSame(\X2Mail\Standalone\Auth\AuthOutcome::Denied, $result2->outcome);

        // The refused login should drop the earlier tokens
        self::assertNull($this->session->get('x2w.tokens'), 'denied re-login should drop earlier tokens');
        self::assertNull($this->session->get('x2w.lifetime'), 'denied re-login should drop earlier lifetime');
        self::assertSame($idToken2, $this->session->get('x2w.logout_hint'), 'denied re-login should store its own ID token as hint');

        // logout() should use the refused login's ID token, not the old session
        $url = $this->auth()->logout();
        self::assertStringStartsWith(self::ISS . '/logout?', $url);
        \parse_str((string) \parse_url($url, \PHP_URL_QUERY), $q);
        self::assertSame($idToken2, $q['id_token_hint'], 'logout should use the refused login\'s ID token, not the old session\'s token');
    }
}
