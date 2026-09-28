<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Http;

use X2Mail\Standalone\Config\ConfigException;
use X2Mail\Standalone\Log;
use X2Mail\Standalone\Oidc\OidcException;

/**
 * Maps any Throwable escaping the front controller to a safe response.
 * Never echoes an exception message or a file path to the client.
 */
final class ErrorResponses
{
    /** @return array{status: int, headers: array<string, string>, body: string} */
    public static function for(\Throwable $e, Log $log): array
    {
        if ($e instanceof ConfigException) {
            $log->warning('config error: ' . $e->getMessage());
            return self::response(500, 'configuration error', 'text/plain');
        }
        if ($e instanceof OidcException) {
            $log->warning('identity provider unavailable: ' . $e->getMessage());
            return self::response(503, Pages::unavailable(), 'text/html; charset=utf-8');
        }
        $log->warning(\get_class($e) . ': ' . $e->getMessage());
        return self::response(500, 'internal error', 'text/plain');
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private static function response(int $status, string $body, string $type): array
    {
        return ['status' => $status, 'headers' => ['Content-Type' => $type, 'Cache-Control' => 'no-store'], 'body' => $body];
    }
}
