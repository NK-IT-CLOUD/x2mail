<?php

declare(strict_types=1);

namespace OCA\X2Mail\Listeners;

use OCA\X2Mail\Service\LogService;
use OCA\X2Mail\Util\EngineHelper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\ISession;
use OCP\User\Events\PostLoginEvent;

/**
 * Store UID + encoded password in session on password login.
 * Skips token logins (bots, DAV clients, API).
 */
/** @implements IEventListener<Event> */
class PasswordLoginListener implements IEventListener
{
    public function __construct(
        private ISession $session,
    ) {
    }

    public function handle(Event $event): void
    {
        if (!($event instanceof PostLoginEvent)) {
            return;
        }

        // Skip engine bootstrap for app-password/token logins (bots, DAV clients, API)
        // — they can't use the password for IMAP anyway
        if ($event->isTokenLogin()) {
            return;
        }

        $uid = $event->getUser()->getUID();
        $this->session->set('x2mail-uid', $uid);
        $this->session->set('x2mail-passphrase', EngineHelper::encodePassword($event->getPassword(), $uid));

        LogService::debug("Password login: uid={$uid}, passphrase stored");
    }
}
