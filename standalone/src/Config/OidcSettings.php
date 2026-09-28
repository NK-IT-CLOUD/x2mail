<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Config;

final class OidcSettings
{
    /** @param list<string> $scopes */
    public function __construct(
        public readonly string $issuer,
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $tenantClaim,
        public readonly string $tenantRegex,
        public readonly array $scopes,
    ) {
    }
}
