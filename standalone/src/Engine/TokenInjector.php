<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Engine;

use X2Mail\Engine\Model\Account;
use X2Mail\Engine\Model\MainAccount;
use X2Mail\Mail\Net\ConnectSettings;
use X2Mail\Standalone\Auth\Identity;

/**
 * Swaps the engine's 'oidc_login|<uid>' sentinel for the live access token at
 * IMAP/SMTP/Sieve connect — same contract as the Nextcloud plugin.
 */
final class TokenInjector
{
    public function apply(Account $account, ConnectSettings $settings, ?Identity $identity): void
    {
        if ($identity === null || !$account instanceof MainAccount
            || !\str_starts_with((string) $settings->passphrase, 'oidc_login|')
        ) {
            return;
        }
        $settings->passphrase = $identity->accessToken;
        $settings->SASLMechanisms = \array_values(\array_unique(
            \array_merge(['OAUTHBEARER'], $settings->SASLMechanisms)
        ));
    }
}
