<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Tests\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use X2Mail\Engine\Host;
use X2Mail\Standalone\Access\RuleEvaluator;
use X2Mail\Standalone\Access\TenantResolver;
use X2Mail\Standalone\Auth\SessionAuth;
use X2Mail\Standalone\Config\Config;
use X2Mail\Standalone\Http\App;
use X2Mail\Standalone\Log;
use X2Mail\Standalone\Oidc\Endpoints;
use X2Mail\Standalone\Oidc\OidcClient;
use X2Mail\Standalone\Oidc\TokenSet;
use X2Mail\Standalone\Oidc\TokenValidator;
use X2Mail\Standalone\Runtime;
use X2Mail\Standalone\Session\ArraySession;
use X2Mail\Standalone\StandaloneBridge;
use X2Mail\Standalone\Tests\Support\TestKeys;

class AppTest extends TestCase
{
    private const ISS = 'https://id.example.org/realms/central';

    private string $secretFile;

    private ArraySession $session;

    private bool $engineRan = false;

    protected function setUp(): void
    {
        $this->secretFile = \tempnam(\sys_get_temp_dir(), 'x2w-sec');
        \file_put_contents($this->secretFile, 's3cret');
        $this->session = new ArraySession();
    }

    protected function tearDown(): void
    {
        \unlink($this->secretFile);
        Host::reset();
        Runtime::reset();
    }

    /** @param list<Response> $responses */
    private function app(array $responses = []): App
    {
        $config = Config::fromArray([
            'webmail' => ['base_url' => 'https://webmail.example.org'],
            'oidc' => ['issuer' => self::ISS, 'client_id' => 'webmail', 'client_secret_file' => $this->secretFile],
            'mail' => ['host' => 'mail.example.org'],
            'rules' => ['mail_user' => ['claim' => 'groups', 'any_of' => ['mail']]],
            'access' => ['login_requires' => 'mail_user'],
        ]);
        $endpoints = new Endpoints(self::ISS . '/auth', self::ISS . '/token', self::ISS . '/userinfo', self::ISS . '/certs', self::ISS . '/logout');
        $oidc = new OidcClient($config, $endpoints, new TokenValidator(TestKeys::keySet(), self::ISS), new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));
        $auth = new SessionAuth($config, new RuleEvaluator(), new TenantResolver('organization', $config->oidc->tenantRegex), $oidc, $this->session, new Log(static function (): void {
        }));
        return new App($config, $auth, $this->session, function (): void {
            $this->engineRan = true;
        });
    }

    private function loggedIn(): void
    {
        $access = TestKeys::issue(['iss' => self::ISS, 'exp' => \time() + 300, 'email' => 'anna@kunde-a.at', 'preferred_username' => 'anna', 'groups' => ['mail']]);
        $this->session->set('x2w.tokens', (new TokenSet($access, 'RT', 'ID', \time() + 300, ['iss' => self::ISS, 'exp' => \time() + 300, 'email' => 'anna@kunde-a.at', 'preferred_username' => 'anna', 'groups' => ['mail']]))->toArray());
    }

    public function testHealth(): void
    {
        $r = $this->app()->handle('/healthz', [], []);
        self::assertSame([200, 'ok'], [$r['status'], $r['body']]);
    }

    public function testHealthAndBlockedDoNotTouchSession(): void
    {
        $config = Config::fromArray([
            'webmail' => ['base_url' => 'https://webmail.example.org'],
            'oidc' => ['issuer' => self::ISS, 'client_id' => 'webmail', 'client_secret_file' => $this->secretFile],
            'mail' => ['host' => 'mail.example.org'],
            'rules' => ['mail_user' => ['claim' => 'groups', 'any_of' => ['mail']]],
            'access' => ['login_requires' => 'mail_user'],
        ]);
        $throwingSession = new class implements \X2Mail\Standalone\Session\Session {
            public function get(string $key): mixed
            {
                throw new \LogicException('session must not be touched');
            }

            public function set(string $key, mixed $value): void
            {
                throw new \LogicException('session must not be touched');
            }

            public function remove(string $key): void
            {
                throw new \LogicException('session must not be touched');
            }

            public function regenerate(): void
            {
                throw new \LogicException('session must not be touched');
            }

            public function destroy(): void
            {
                throw new \LogicException('session must not be touched');
            }

            public function id(): string
            {
                throw new \LogicException('session must not be touched');
            }
        };
        $closure = static function (): OidcClient {
            throw new \LogicException('OIDC factory should not be called');
        };
        $auth = new SessionAuth($config, new RuleEvaluator(), new TenantResolver('organization', $config->oidc->tenantRegex), $closure, $throwingSession, new Log(static function (): void {
        }));
        $app = new App($config, $auth, $throwingSession, function (): void {
            $this->engineRan = true;
        });

        $health = $app->handle('/healthz', [], []);
        self::assertSame(200, $health['status']);

        $blocked = $app->handle('/x2mail/v/current/setup.php', [], []);
        self::assertSame(404, $blocked['status']);
    }

    public function testUnauthenticatedPageRedirectsToLogin(): void
    {
        $r = $this->app()->handle('/', [], []);
        self::assertSame(302, $r['status']);
        self::assertSame('/oidc/login', $r['headers']['Location']);
        self::assertFalse($this->engineRan);
    }

    public function testUnauthenticatedJsonGets401NotRedirect(): void
    {
        $r = $this->app()->handle('/?/Json/&q[]=/0/', ['/Json/' => ''], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertSame(401, $r['status']);
        self::assertSame('application/json', $r['headers']['Content-Type']);
        self::assertFalse($this->engineRan);
    }

    public function testUnauthenticatedAppDataWithAcceptHeaderGets401(): void
    {
        $r = $this->app()->handle('/?/AppData/0/abc/', [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(401, $r['status']);
        self::assertSame('application/json', $r['headers']['Content-Type']);
        self::assertFalse($this->engineRan);
    }

    public function testRedirectLoopIsCappedAfterThreeAttempts(): void
    {
        $app = $this->app();
        $r1 = $app->handle('/', [], []);
        $r2 = $app->handle('/', [], []);
        $r3 = $app->handle('/', [], []);
        $r4 = $app->handle('/', [], []);

        self::assertSame(302, $r1['status']);
        self::assertSame(302, $r2['status']);
        self::assertSame(302, $r3['status']);
        self::assertSame(503, $r4['status']);

        $this->loggedIn();
        $r5 = $app->handle('/', [], []);
        self::assertNull($r5);
        self::assertNull($this->session->get('x2w.redirects'));
    }

    public function testSuccessfulCallbackResetsRedirectLoopCounterImmediately(): void
    {
        $app = $this->app();
        $app->handle('/', [], []);
        $app->handle('/', [], []);
        $app->handle('/', [], []);
        self::assertSame(503, $app->handle('/', [], [])['status']);

        $app->handle('/oidc/login', [], []);
        $pending = $this->session->get('x2w.login');
        $access = TestKeys::issue(['iss' => self::ISS, 'exp' => \time() + 300, 'email' => 'anna@kunde-a.at', 'preferred_username' => 'anna', 'groups' => ['mail']]);
        $id = TestKeys::issue(['iss' => self::ISS, 'aud' => 'webmail', 'exp' => \time() + 300, 'nonce' => $pending['nonce']]);
        $token = new Response(200, ['Content-Type' => 'application/json'], (string) \json_encode(['access_token' => $access, 'id_token' => $id, 'refresh_token' => 'RT', 'expires_in' => 300, 'token_type' => 'Bearer']));

        $r = $this->app([$token])->handle('/oidc/callback?code=C&state=' . $pending['state'], ['code' => 'C', 'state' => $pending['state']], []);

        self::assertSame(302, $r['status']);
        self::assertNull($this->session->get('x2w.redirects'), 'the loop counter must reset right after a successful callback, not only on the next request');
    }

    public function testAdminPanelBlockedEvenWhenLoggedIn(): void
    {
        $this->loggedIn();
        $r = $this->app()->handle('/?admin', ['admin' => ''], []);
        self::assertSame(404, $r['status']);
        self::assertFalse($this->engineRan);
    }

    public function testLoginRedirectsToIdp(): void
    {
        $r = $this->app()->handle('/oidc/login', [], []);
        self::assertSame(302, $r['status']);
        self::assertStringStartsWith(self::ISS . '/auth?', $r['headers']['Location']);
    }

    public function testCallbackRedirectsOnlyToBaseUrl(): void
    {
        $this->app()->handle('/oidc/login', [], []);
        $pending = $this->session->get('x2w.login');
        $access = TestKeys::issue(['iss' => self::ISS, 'exp' => \time() + 300, 'email' => 'anna@kunde-a.at', 'groups' => ['mail']]);
        $id = TestKeys::issue(['iss' => self::ISS, 'aud' => 'webmail', 'exp' => \time() + 300, 'nonce' => $pending['nonce']]);
        $token = new Response(200, ['Content-Type' => 'application/json'], (string) \json_encode(['access_token' => $access, 'id_token' => $id, 'refresh_token' => 'RT', 'expires_in' => 300, 'token_type' => 'Bearer']));

        $r = $this->app([$token])->handle('/oidc/callback?code=C&state=' . $pending['state'] . '&redirect=https://evil.example', ['code' => 'C', 'state' => $pending['state'], 'redirect' => 'https://evil.example'], []);

        self::assertSame(302, $r['status']);
        self::assertSame('https://webmail.example.org/', $r['headers']['Location']);
    }

    public function testCallbackWithTokenEndpointFailureShows503(): void
    {
        $this->app()->handle('/oidc/login', [], []);
        $pending = $this->session->get('x2w.login');
        $token = new Response(500, [], 'boom');

        $r = $this->app([$token])->handle('/oidc/callback?code=C&state=' . $pending['state'], ['code' => 'C', 'state' => $pending['state']], []);

        self::assertSame(503, $r['status']);
        self::assertFalse($this->engineRan);
    }

    public function testCallbackWithoutPendingLoginShows400(): void
    {
        // No prior /oidc/login: second tab, back button, or session GC — a
        // benign flow failure, not an access denial.
        $r = $this->app()->handle('/oidc/callback?code=C&state=S', ['code' => 'C', 'state' => 'S'], []);
        self::assertSame(400, $r['status']);
        self::assertStringNotContainsString('<script', $r['body']);
    }

    public function testCallbackWithIdpErrorShows400(): void
    {
        $r = $this->app()->handle('/oidc/callback?error=access_denied', ['error' => 'access_denied'], []);
        self::assertSame(400, $r['status']);
    }

    public function testCallbackWithForgedStateShows400(): void
    {
        $this->app()->handle('/oidc/login', [], []);
        $r = $this->app()->handle('/oidc/callback?code=C&state=forged', ['code' => 'C', 'state' => 'forged'], []);
        self::assertSame(400, $r['status']);
        self::assertStringContainsString('abgelaufen', $r['body']);
    }

    public function testCallbackDeniedByLoginRuleShows403(): void
    {
        $config = Config::fromArray([
            'webmail' => ['base_url' => 'https://webmail.example.org'],
            'oidc' => ['issuer' => self::ISS, 'client_id' => 'webmail', 'client_secret_file' => $this->secretFile],
            'mail' => ['host' => 'mail.example.org'],
            'rules' => ['mail_user' => ['claim' => 'groups', 'any_of' => ['mail']]],
            'access' => ['login_requires' => 'mail_user'],
        ]);
        $endpoints = new Endpoints(self::ISS . '/auth', self::ISS . '/token', self::ISS . '/userinfo', self::ISS . '/certs', self::ISS . '/logout');
        $oidc = new OidcClient($config, $endpoints, new TokenValidator(TestKeys::keySet(), self::ISS), new Client(['handler' => HandlerStack::create(new MockHandler([]))]));
        $auth = new SessionAuth($config, new RuleEvaluator(), new TenantResolver('organization', $config->oidc->tenantRegex), $oidc, $this->session, new Log(static function (): void {
        }));
        $app = new App($config, $auth, $this->session, function (): void {
            $this->engineRan = true;
        });

        $app->handle('/oidc/login', [], []);
        $pending = $this->session->get('x2w.login');
        $access = TestKeys::issue(['iss' => self::ISS, 'exp' => \time() + 300, 'email' => 'anna@kunde-a.at', 'groups' => ['other']]);
        $id = TestKeys::issue(['iss' => self::ISS, 'aud' => 'webmail', 'exp' => \time() + 300, 'nonce' => $pending['nonce']]);
        $token = new Response(200, ['Content-Type' => 'application/json'], (string) \json_encode(['access_token' => $access, 'id_token' => $id, 'refresh_token' => 'RT', 'expires_in' => 300, 'token_type' => 'Bearer']));

        $oidc2 = new OidcClient($config, $endpoints, new TokenValidator(TestKeys::keySet(), self::ISS), new Client(['handler' => HandlerStack::create(new MockHandler([$token]))]));
        $auth2 = new SessionAuth($config, new RuleEvaluator(), new TenantResolver('organization', $config->oidc->tenantRegex), $oidc2, $this->session, new Log(static function (): void {
        }));
        $app2 = new App($config, $auth2, $this->session, function (): void {
        });
        $r = $app2->handle('/oidc/callback?code=C&state=' . $pending['state'], ['code' => 'C', 'state' => $pending['state']], []);

        self::assertSame(403, $r['status']);
    }

    public function testCallbackDeniedClearsRedirectLoopCounter(): void
    {
        $app = $this->app();
        // Simulate reaching the redirect loop counter
        $this->session->set('x2w.redirects', 3);

        // Attempt login that will be denied
        $app->handle('/oidc/login', [], []);
        $pending = $this->session->get('x2w.login');
        $access = TestKeys::issue(['iss' => self::ISS, 'exp' => \time() + 300, 'email' => 'anna@kunde-a.at', 'groups' => ['other']]);
        $id = TestKeys::issue(['iss' => self::ISS, 'aud' => 'webmail', 'exp' => \time() + 300, 'nonce' => $pending['nonce']]);
        $token = new Response(200, ['Content-Type' => 'application/json'], (string) \json_encode(['access_token' => $access, 'id_token' => $id, 'refresh_token' => 'RT', 'expires_in' => 300, 'token_type' => 'Bearer']));

        $config = Config::fromArray([
            'webmail' => ['base_url' => 'https://webmail.example.org'],
            'oidc' => ['issuer' => self::ISS, 'client_id' => 'webmail', 'client_secret_file' => $this->secretFile],
            'mail' => ['host' => 'mail.example.org'],
            'rules' => ['mail_user' => ['claim' => 'groups', 'any_of' => ['mail']]],
            'access' => ['login_requires' => 'mail_user'],
        ]);
        $endpoints = new Endpoints(self::ISS . '/auth', self::ISS . '/token', self::ISS . '/userinfo', self::ISS . '/certs', self::ISS . '/logout');
        $oidc = new OidcClient($config, $endpoints, new TokenValidator(TestKeys::keySet(), self::ISS), new Client(['handler' => HandlerStack::create(new MockHandler([$token]))]));
        $auth = new SessionAuth($config, new RuleEvaluator(), new TenantResolver('organization', $config->oidc->tenantRegex), $oidc, $this->session, new Log(static function (): void {
        }));
        $app2 = new App($config, $auth, $this->session, function (): void {
        });

        $r = $app2->handle('/oidc/callback?code=C&state=' . $pending['state'], ['code' => 'C', 'state' => $pending['state']], []);

        // Should be 403 and counter should be cleared
        self::assertSame(403, $r['status']);
        self::assertNull($this->session->get('x2w.redirects'));
    }

    public function testLogoutRedirectsToIdp(): void
    {
        $this->loggedIn();
        $r = $this->app()->handle('/oidc/logout', [], []);
        self::assertSame(302, $r['status']);
        self::assertStringStartsWith(self::ISS . '/logout?', $r['headers']['Location']);
    }

    public function testLoggedInHandsOverToEngineWithBridgeAndRuntime(): void
    {
        $this->loggedIn();
        $r = $this->app()->handle('/', [], []);

        self::assertNull($r);
        self::assertTrue($this->engineRan);
        self::assertInstanceOf(StandaloneBridge::class, Host::get());
        self::assertSame('anna@kunde-a.at', Host::get()->ssoEmail());
        self::assertSame('anna@kunde-a.at', Runtime::identity()?->email);
    }

    public function testHandOverWithoutThemeLeavesPrimaryColorUnset(): void
    {
        $this->loggedIn();
        $this->app()->handle('/', [], []);

        self::assertTrue($this->engineRan);
        self::assertNull(Runtime::primaryColor());
    }

    public function testHandOverSetsPrimaryColorFromConfig(): void
    {
        $this->loggedIn();
        $r = $this->appWithDav([], ['primary_color' => '#aa3300'])->handle('/', [], []);

        self::assertNull($r);
        self::assertSame('#aa3300', Runtime::primaryColor());
    }

    /**
     * @param array<string, mixed> $davConfig
     * @param array<string, mixed>|null $theme
     */
    private function appWithDav(array $davConfig, ?array $theme = null): App
    {
        $config = Config::fromArray(($theme === null ? [] : ['theme' => $theme]) + [
            'webmail' => ['base_url' => 'https://webmail.example.org', 'data_dir' => '/var/lib/x2mail-webmail'],
            'oidc' => ['issuer' => self::ISS, 'client_id' => 'webmail', 'client_secret_file' => $this->secretFile],
            'mail' => ['host' => 'mail.example.org'],
            'rules' => [
                'mail_user' => ['claim' => 'groups', 'any_of' => ['mail']],
                'dav_user' => ['claim' => 'groups', 'any_of' => ['dav']],
            ],
            'access' => ['login_requires' => 'mail_user'],
            'dav' => $davConfig,
        ]);
        $endpoints = new Endpoints(self::ISS . '/auth', self::ISS . '/token', self::ISS . '/userinfo', self::ISS . '/certs', self::ISS . '/logout');
        $oidc = new OidcClient($config, $endpoints, new TokenValidator(TestKeys::keySet(), self::ISS), new Client(['handler' => HandlerStack::create(new MockHandler([]))]));
        $auth = new SessionAuth($config, new RuleEvaluator(), new TenantResolver('organization', $config->oidc->tenantRegex), $oidc, $this->session, new Log(static function (): void {
        }));
        return new App($config, $auth, $this->session, function (): void {
            $this->engineRan = true;
        });
    }

    /** @param array<string, mixed> $extraClaims */
    private function loggedInWithClaims(array $extraClaims): void
    {
        $claims = \array_merge(['iss' => self::ISS, 'exp' => \time() + 300, 'email' => 'anna@kunde-a.at', 'preferred_username' => 'anna', 'groups' => ['mail']], $extraClaims);
        $access = TestKeys::issue($claims);
        $this->session->set('x2w.tokens', (new TokenSet($access, 'RT', 'ID', \time() + 300, $claims))->toArray());
    }

    public function testHandOverSetsDavContextWhenFeatureActive(): void
    {
        $app = $this->appWithDav([
            'contacts' => [
                'type' => 'carddav',
                'requires' => 'dav_user',
                'url_template' => 'https://nc.example.org/remote.php/dav/{tenant}/',
                'timeout_s' => 7,
                'include_system_addressbook' => false,
                'writable' => 'personal',
            ],
        ]);
        $this->loggedInWithClaims(['groups' => ['mail', 'dav'], 'organization' => ['kunde-a']]);

        $r = $app->handle('/', [], []);

        self::assertNull($r);
        $ctx = Runtime::dav();
        self::assertNotNull($ctx);
        self::assertSame('https://nc.example.org/remote.php/dav/kunde-a/', $ctx->davRoot);
        self::assertSame(7, $ctx->timeoutS);
        self::assertTrue($ctx->writablePersonal);
        self::assertFalse($ctx->includeSystem);
        self::assertSame(\hash('sha256', 'anna'), $ctx->userKey);
        self::assertSame('/var/lib/x2mail-webmail/cache', $ctx->cacheDir);
    }

    public function testNoDavContextWithoutFeature(): void
    {
        $app = $this->appWithDav([
            'contacts' => [
                'type' => 'carddav',
                'requires' => 'dav_user',
                'url_template' => 'https://nc.example.org/remote.php/dav/{tenant}/',
                'timeout_s' => 5,
                'writable' => 'personal',
            ],
        ]);
        // No "dav" group, so the dav_user rule is not met.
        $this->loggedInWithClaims(['groups' => ['mail'], 'organization' => ['kunde-a']]);

        $app->handle('/', [], []);

        self::assertNull(Runtime::dav());
    }

    public function testNoDavContextWhenTenantInvalidAndTemplateNeedsTenant(): void
    {
        $app = $this->appWithDav([
            'contacts' => [
                'type' => 'carddav',
                'requires' => 'dav_user',
                'url_template' => 'https://nc.example.org/remote.php/dav/{tenant}/',
                'timeout_s' => 5,
                'writable' => 'personal',
            ],
        ]);
        // groups satisfy dav_user, but no organization claim -> no tenant.
        $this->loggedInWithClaims(['groups' => ['mail', 'dav']]);

        $app->handle('/', [], []);

        self::assertNull(Runtime::dav());
    }

    public function testDavContextUrlWithoutTenantPlaceholder(): void
    {
        $app = $this->appWithDav([
            'contacts' => [
                'type' => 'carddav',
                'requires' => 'dav_user',
                'url_template' => 'https://nc.example.org/remote.php/dav/',
                'timeout_s' => 5,
                'writable' => 'personal',
            ],
        ]);
        // Feature is active with no organization claim at all, since the
        // template has no {tenant} placeholder to satisfy.
        $this->loggedInWithClaims(['groups' => ['mail', 'dav']]);

        $app->handle('/', [], []);

        $ctx = Runtime::dav();
        self::assertNotNull($ctx);
        self::assertSame('https://nc.example.org/remote.php/dav/', $ctx->davRoot);
    }

    public function testBlockedPathIs404(): void
    {
        $this->loggedIn();
        self::assertSame(404, $this->app()->handle('/x2mail/v/current/setup.php', [], [])['status']);
        self::assertFalse($this->engineRan);
    }

    public function testHealthNeedsNoOidc(): void
    {
        $config = Config::fromArray([
            'webmail' => ['base_url' => 'https://webmail.example.org'],
            'oidc' => ['issuer' => self::ISS, 'client_id' => 'webmail', 'client_secret_file' => $this->secretFile],
            'mail' => ['host' => 'mail.example.org'],
            'rules' => ['mail_user' => ['claim' => 'groups', 'any_of' => ['mail']]],
            'access' => ['login_requires' => 'mail_user'],
        ]);

        $closure = static function (): OidcClient {
            throw new \LogicException('OIDC factory should not be called for /healthz');
        };

        $auth = new SessionAuth($config, new RuleEvaluator(), new TenantResolver('organization', $config->oidc->tenantRegex), $closure, $this->session, new Log(static function (): void {
        }));
        $app = new App($config, $auth, $this->session, function (): void {
            $this->engineRan = true;
        });

        $r = $app->handle('/healthz', [], []);
        self::assertSame(200, $r['status']);
        self::assertSame('ok', $r['body']);
        self::assertFalse($this->engineRan);
    }

    /** @param array<string, mixed> $overrides */
    private function writeEnvConfig(string $dir, array $overrides = []): string
    {
        $secretFile = $dir . '/secret';
        \file_put_contents($secretFile, 's3cret');
        $dataDir = $overrides['data_dir'] ?? $dir . '/data';
        $issuer = $overrides['issuer'] ?? 'https://127.0.0.1:1/realms/void';
        $toml = <<<TOML
        [webmail]
        base_url = "https://webmail.example.org"
        data_dir = "{$dataDir}"
        [oidc]
        issuer = "{$issuer}"
        client_id = "webmail"
        client_secret_file = "{$secretFile}"
        [mail]
        host = "mail.example.org"
        [rules.mail_user]
        claim = "groups"
        any_of = ["mail"]
        [access]
        login_requires = "mail_user"
        TOML;
        \file_put_contents($dir . '/webmail.toml', $toml);
        return $dir . '/webmail.toml';
    }

    private function rmrf(string $path): void
    {
        if (\is_dir($path) && !\is_link($path)) {
            foreach (\scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->rmrf($path . '/' . $entry);
                }
            }
            \rmdir($path);
        } elseif (\file_exists($path) || \is_link($path)) {
            \unlink($path);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFromEnvironmentCreatesMissingCacheDirectory(): void
    {
        $dir = \sys_get_temp_dir() . '/x2w-fromenv-' . \bin2hex(\random_bytes(4));
        \mkdir($dir, 0700, true);
        $configPath = $this->writeEnvConfig($dir);
        \putenv('X2W_CONFIG=' . $configPath);

        try {
            $app = App::fromEnvironment();
            // Trigger OIDC client creation, which creates the cache directory
            $app->handle('/oidc/login', [], []);
        } catch (\Throwable) {
            // Discovery against an unreachable issuer is expected to fail;
            // only the directory creation is under test here.
        } finally {
            \putenv('X2W_CONFIG');
        }

        self::assertDirectoryExists($dir . '/data/cache');
        $this->rmrf($dir);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFromEnvironmentThrowsWhenCacheDirectoryCannotBeCreated(): void
    {
        $dir = \sys_get_temp_dir() . '/x2w-fromenv-fail-' . \bin2hex(\random_bytes(4));
        \mkdir($dir, 0700, true);
        \file_put_contents($dir . '/blocker', 'x'); // a plain file blocks mkdir() on this path
        $configPath = $this->writeEnvConfig($dir, ['data_dir' => $dir . '/blocker/data']);
        \putenv('X2W_CONFIG=' . $configPath);

        try {
            $app = App::fromEnvironment();
            // Trigger OIDC client creation to test cache directory creation failure
            $app->handle('/oidc/login', [], []);
            self::fail('expected RuntimeException');
        } catch (\Throwable $e) {
            // Cache directory creation error happens when OIDC factory is first called.
            // It must fail with RuntimeException before reaching discovery/network.
            self::assertNotInstanceOf(\X2Mail\Standalone\Oidc\OidcException::class, $e);
            self::assertInstanceOf(\RuntimeException::class, $e);
            self::assertStringContainsString('cannot create', $e->getMessage());
        } finally {
            \putenv('X2W_CONFIG');
            $this->rmrf($dir);
        }
    }
}
