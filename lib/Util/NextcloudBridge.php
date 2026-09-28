<?php

declare(strict_types=1);

namespace OCA\X2Mail\Util;

use X2Mail\Engine\HostBridge;

/**
 * Serves the engine core's host needs from the Nextcloud session. Thin adapter:
 * all logic stays in EngineHelper so NC behaviour is unchanged.
 */
final class NextcloudBridge implements HostBridge
{
    public function __construct(
        private EngineHelper $helper,
    ) {
    }

    public function isSsoLogin(): bool
    {
        return $this->helper->isOIDCLogin();
    }

    public function ssoEmail(): ?string
    {
        return $this->helper->getSsoEmail();
    }

    public function ssoUid(): ?string
    {
        return $this->helper->getSsoUid();
    }

    public function sessionSeed(): ?string
    {
        return $this->helper->getNcSessionId();
    }
}
