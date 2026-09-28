<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use X2Mail\Engine\Host;
use X2Mail\Standalone\Access\RuleEvaluator;
use X2Mail\Standalone\Access\TenantResolver;
use X2Mail\Standalone\Auth\AuthOutcome;
use X2Mail\Standalone\Auth\Identity;
use X2Mail\Standalone\Auth\SessionAuth;
use X2Mail\Standalone\Config\Config;
use X2Mail\Standalone\Dav\DavContext;
use X2Mail\Standalone\Log;
use X2Mail\Standalone\Oidc\Discovery;
use X2Mail\Standalone\Oidc\OidcClient;
use X2Mail\Standalone\Oidc\TokenValidator;
use X2Mail\Standalone\Runtime;
use X2Mail\Standalone\Session\PhpSession;
use X2Mail\Standalone\Session\Session;
use X2Mail\Standalone\StandaloneBridge;

/**
 * Front controller. Returns a response array, or null once the engine has
 * taken over. The session stays open through current() — that serialises
 * refreshes — and is closed before the engine runs.
 */
final class App
{
    private const REDIRECTS = 'x2w.redirects';
    private const MAX_REDIRECTS = 3;

    /** @param \Closure(): void $engine */
    public function __construct(
        private Config $config,
        private SessionAuth $auth,
        private Session $session,
        private \Closure $engine,
        private Router $router = new Router(),
    ) {
    }

    public static function fromEnvironment(): self
    {
        $config = Config::fromFile(\getenv('X2W_CONFIG') ?: '/etc/x2mail-webmail/webmail.toml');
        $http = new Client(['timeout' => 5, 'connect_timeout' => 3]);
        $factory = new HttpFactory();

        $oidcFactory = static function () use ($config, $http, $factory): OidcClient {
            $cacheDir = $config->dataDir . '/cache';
            if (!\is_dir($cacheDir) && !@\mkdir($cacheDir, 0750, true) && !\is_dir($cacheDir)) {
                throw new \RuntimeException("{$cacheDir}: cannot create data directory");
            }
            $endpoints = (new Discovery($http, $factory, $cacheDir . '/discovery.json'))->load($config->oidc->issuer);
            $keys = new \Firebase\JWT\CachedKeySet(
                $endpoints->jwksUri,
                $http,
                $factory,
                new FilesystemAdapter('jwks', 3600, $cacheDir),
                3600,
                true,
            );
            return new OidcClient($config, $endpoints, new TokenValidator($keys, $config->oidc->issuer), $http);
        };

        $session = PhpSession::lazy();
        $auth = new SessionAuth(
            $config,
            new RuleEvaluator(),
            new TenantResolver($config->oidc->tenantClaim, $config->oidc->tenantRegex),
            $oidcFactory,
            $session,
            new Log(),
        );
        $engineIndex = \dirname(__DIR__, 3) . '/app/index.php';
        return new self($config, $auth, $session, static function () use ($config, $engineIndex): void {
            if (!\defined('APP_DATA_FOLDER_PATH')) {
                \define('APP_DATA_FOLDER_PATH', $config->dataDir . '/engine/');
            }
            require $engineIndex;
        });
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $server
     * @return array{status: int, headers: array<string, string>, body: string}|null
     */
    public function handle(string $uri, array $query, array $server): ?array
    {
        switch ($this->router->route($uri)) {
            case Route::Health:
                return self::response(200, 'ok', 'text/plain');
            case Route::Blocked:
                return self::response(404, 'not found', 'text/plain');
            case Route::Login:
                return self::redirect($this->auth->startLogin());
            case Route::Logout:
                return self::redirect($this->auth->logout());
            case Route::Callback:
                $code = $query['code'] ?? null;
                $state = $query['state'] ?? null;
                if (!\is_string($code) || !\is_string($state) || isset($query['error'])) {
                    // Malformed callback or an IdP error= response — a benign
                    // flow failure (direct hit, cancelled consent), not a
                    // denial of the account.
                    return self::response(400, Pages::expired(), 'text/html; charset=utf-8');
                }
                $result = $this->auth->finishLogin($code, $state);
                if ($result->outcome === AuthOutcome::Ok) {
                    $this->session->remove(self::REDIRECTS);
                    return self::redirect($this->config->baseUrl . '/');
                }
                if ($result->outcome === AuthOutcome::Denied) {
                    $this->session->remove(self::REDIRECTS);
                }
                return match ($result->outcome) {
                    AuthOutcome::Unavailable => self::response(503, Pages::unavailable(), 'text/html; charset=utf-8'),
                    AuthOutcome::Expired => self::response(400, Pages::expired(), 'text/html; charset=utf-8'),
                    AuthOutcome::Denied => self::response(403, Pages::forbidden(), 'text/html; charset=utf-8'),
                };
            case Route::Engine:
                break;
        }

        $identity = $this->auth->current(\time());
        if ($identity === null) {
            if (self::isAjax($uri, $server)) {
                return self::response(401, '{"error":"login required"}', 'application/json');
            }
            $redirects = (int) ($this->session->get(self::REDIRECTS) ?? 0);
            if ($redirects >= self::MAX_REDIRECTS) {
                return self::response(503, Pages::unavailable(), 'text/html; charset=utf-8');
            }
            $this->session->set(self::REDIRECTS, $redirects + 1);
            return self::redirect('/oidc/login');
        }
        $this->session->remove(self::REDIRECTS);

        Host::set(new StandaloneBridge($identity, $this->session->id()));
        Runtime::set($identity);
        Runtime::setDav($this->resolveDav($identity));
        Runtime::setPrimaryColor($this->config->theme->primaryColor);
        if ($this->session instanceof PhpSession) {
            $this->session->close();
        }
        ($this->engine)();
        return null;
    }

    /** The first enabled carddav service whose feature is active for $identity, or null. */
    private function resolveDav(Identity $identity): ?DavContext
    {
        foreach ($this->config->dav as $name => $service) {
            if ($service->type !== 'carddav' || ($identity->features[$name] ?? false) !== true) {
                continue;
            }

            $davRoot = $service->urlTemplate;
            if (\str_contains($davRoot, '{tenant}')) {
                if ($identity->tenant === null) {
                    continue;
                }
                $davRoot = \str_replace('{tenant}', $identity->tenant, $davRoot);
            }

            return new DavContext(
                $davRoot,
                $service->timeoutS,
                $service->writable === 'personal',
                $service->includeSystemAddressbook,
                \hash('sha256', $identity->uid),
                $this->config->dataDir . '/cache',
            );
        }

        return null;
    }

    /** @param array<string, mixed> $server */
    private static function isAjax(string $uri, array $server): bool
    {
        return \str_contains($uri, '/Json/')
            || ($server['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
            || \str_contains((string) ($server['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private static function redirect(string $location): array
    {
        return ['status' => 302, 'headers' => ['Location' => $location, 'Cache-Control' => 'no-store'], 'body' => ''];
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private static function response(int $status, string $body, string $type): array
    {
        return ['status' => $status, 'headers' => ['Content-Type' => $type, 'Cache-Control' => 'no-store'], 'body' => $body];
    }
}
