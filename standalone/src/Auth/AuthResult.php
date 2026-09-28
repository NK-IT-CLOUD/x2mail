<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Auth;

final class AuthResult
{
    public readonly bool $ok;

    public function __construct(
        public readonly AuthOutcome $outcome,
        public readonly ?string $reason = null,
    ) {
        $this->ok = $outcome === AuthOutcome::Ok;
    }
}
