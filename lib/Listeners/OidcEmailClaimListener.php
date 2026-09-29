<?php

declare(strict_types=1);

namespace OCA\X2Mail\Listeners;

use OCA\X2Mail\Service\LogService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\ISession;

/**
 * Keep the email claim of the OIDC login as the engine identity.
 *
 * user_oidc dispatches AttributeMappedEvent("mappingEmail") while provisioning
 * the user, after it verified the ID token (signature, issuer, audience, nonce).
 * The value is the one user_oidc writes to the profile email, normalised the
 * same way Nextcloud stores it. TokenBridgeListener drops it when a new OIDC
 * login starts, LogoutListener and ImpersonateListener when the session changes
 * hands.
 *
 * IMPORTANT: Do NOT import any OCA\UserOIDC classes here.
 */
/** @implements IEventListener<Event> */
class OidcEmailClaimListener implements IEventListener
{
    public const SESSION_KEY = 'x2mail-oidc-email';

    /** ProviderService::SETTING_MAPPING_EMAIL in user_oidc */
    private const EMAIL_ATTRIBUTE = 'mappingEmail';

    public function __construct(
        private ISession $session,
        private LogService $logService,
    ) {
    }

    public function handle(Event $event): void
    {
        if (!\method_exists($event, 'getAttribute') || !\method_exists($event, 'getValue')) {
            return;
        }
        if ($event->getAttribute() !== self::EMAIL_ATTRIBUTE) {
            return;
        }

        $email = $event->getValue();
        if (!\is_string($email) || \trim($email) === '') {
            $this->session->remove(self::SESSION_KEY);
            $this->logService->warning('OIDC login without email claim; X2Mail has no identity for this session');
            return;
        }

        $this->session->set(self::SESSION_KEY, \mb_strtolower(\trim($email)));
    }
}
