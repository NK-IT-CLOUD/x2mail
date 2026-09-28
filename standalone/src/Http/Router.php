<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Http;

/**
 * Only these paths exist. Everything PHP that is not the engine index — setup
 * scripts, the admin panel, unknown /oidc/* — is Blocked, logged in or not.
 */
final class Router
{
    public function route(string $uri): Route
    {
        $path = (string) \parse_url($uri, \PHP_URL_PATH);
        $query = (string) \parse_url($uri, \PHP_URL_QUERY);

        if (\str_contains($path, '..')) {
            return Route::Blocked;
        }
        return match ($path) {
            '/oidc/login' => Route::Login,
            '/oidc/callback' => Route::Callback,
            '/oidc/logout' => Route::Logout,
            '/healthz' => Route::Health,
            '/', '/index.php' => self::isAdmin($query) ? Route::Blocked : Route::Engine,
            default => Route::Blocked,
        };
    }

    private static function isAdmin(string $query): bool
    {
        foreach (\explode('&', $query) as $part) {
            if (\strtolower(\explode('=', $part, 2)[0]) === 'admin') {
                return true;
            }
        }
        return false;
    }
}
