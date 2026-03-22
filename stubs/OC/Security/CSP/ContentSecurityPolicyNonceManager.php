<?php

declare(strict_types=1);

namespace OC\Security\CSP;

/** Stub for PHPStan — internal NC class not in OCP stubs. */
class ContentSecurityPolicyNonceManager
{
    public function getNonce(): string
    {
        return '';
    }

    public function browserSupportsCspV3(): bool
    {
        return false;
    }
}
