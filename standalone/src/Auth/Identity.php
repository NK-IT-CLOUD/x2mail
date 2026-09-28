<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Auth;

/** The logged-in user of this request, derived from the current access token. */
final class Identity
{
    /** @param array<string, bool> $features DAV service name => active */
    public function __construct(
        public readonly string $email,
        public readonly string $uid,
        public readonly ?string $tenant,
        public readonly string $accessToken,
        public readonly array $features,
    ) {
    }
}
