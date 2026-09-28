<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Access;

/**
 * The tenant is the single alias in the organization claim. It only selects
 * the DAV host — an invalid value disables DAV, never the login.
 */
final class TenantResolver
{
    public function __construct(
        private string $claim,
        private string $regex,
    ) {
    }

    /** @param array<string, mixed> $claims */
    public function resolve(array $claims): TenantResult
    {
        $raw = Claims::at($claims, $this->claim);
        if ($raw === null) {
            return new TenantResult(null, "claim '{$this->claim}' missing");
        }
        $values = Claims::stringList($raw);
        if ($values === null) {
            return new TenantResult(null, "claim '{$this->claim}' is not a list of strings");
        }
        if (\count($values) !== 1) {
            return new TenantResult(null, "expected exactly one organization, got " . \count($values));
        }
        if (\preg_match($this->regex, $values[0]) !== 1) {
            return new TenantResult(null, 'organization does not match tenant_pattern');
        }
        return new TenantResult($values[0], null);
    }
}
