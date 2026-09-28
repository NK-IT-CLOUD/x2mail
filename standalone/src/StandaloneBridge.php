<?php

declare(strict_types=1);

namespace X2Mail\Standalone;

use X2Mail\Engine\HostBridge;
use X2Mail\Standalone\Auth\Identity;

/** Engine host for the standalone webmail: identity from the OIDC session. */
final class StandaloneBridge implements HostBridge
{
    public function __construct(
        private ?Identity $identity,
        private string $sessionId,
    ) {
    }

    public function isSsoLogin(): bool
    {
        return $this->identity !== null;
    }

    public function ssoEmail(): ?string
    {
        return $this->identity?->email;
    }

    public function ssoUid(): ?string
    {
        return $this->identity?->uid;
    }

    public function sessionSeed(): ?string
    {
        return $this->sessionId !== '' ? $this->sessionId : null;
    }
}
