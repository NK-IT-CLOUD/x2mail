<?php

declare(strict_types=1);

namespace OCA\X2Mail\Listeners;

use OCA\X2Mail\Service\LogService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\ISession;
use OCP\User\Events\UserLoggedInEvent;

/**
 * Set snappymail-nc-uid on UserLoggedInEvent.
 * Also sets snappymail-passphrase for OIDC sessions.
 */
/** @implements IEventListener<Event> */
class LoginBridgeListener implements IEventListener
{
    public function __construct(
        private ISession $session,
    ) {
    }

    public function handle(Event $event): void
    {
        if (!($event instanceof UserLoggedInEvent)) {
            return;
        }

        $uid = $event->getUser()->getUID();
        $this->session->set('snappymail-nc-uid', $uid);

        if ($this->session->get('is_oidc')) {
            $this->session->set('snappymail-passphrase', 'oidc_token_bridge');
            LogService::info("Login bridge: uid={$uid}, is_oidc=true");
        } else {
            LogService::debug("Login bridge: uid={$uid}, is_oidc=false (password auth)");
        }
    }
}
