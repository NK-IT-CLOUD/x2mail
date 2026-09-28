<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Access;

/** Either a tenant or the reason there is none — never both. */
final class TenantResult
{
    public function __construct(
        public readonly ?string $tenant,
        public readonly ?string $reason,
    ) {
    }
}
