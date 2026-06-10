<?php

declare(strict_types=1);

namespace OCA\X2Mail\Util;

/**
 * Shared SSL-mode and OIDC-provider resolution used by the setup command,
 * the status command, and the setup controller.
 */
trait SetupResolvers
{
    private function normalizeSslMode(string $ssl): string
    {
        $ssl = \strtolower(\trim($ssl));
        return $ssl === 'tls' ? 'starttls' : $ssl;
    }

    /**
     * Only user_oidc is supported (oidc_login is unmaintained and stops at
     * NC 33). A stored legacy "oidc_login" value normalizes to null.
     */
    private function normalizeOidcProvider(string $provider): ?string
    {
        $provider = \strtolower(\trim($provider));
        return $provider === 'user_oidc' ? $provider : null;
    }
}
