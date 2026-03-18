<?php

declare(strict_types=1);

namespace OCA\X2Mail\Listeners;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\ISession;
use OCP\User\Events\UserLoggedInEvent;

/**
 * Set snappymail-nc-uid on UserLoggedInEvent.
 *
 * This ensures the SM session UID is set for all login methods,
 * including OIDC logins that go through user_oidc.
 */
class LoginBridgeListener implements IEventListener {
	private ISession $session;

	public function __construct(ISession $session) {
		$this->session = $session;
	}

	public function handle(Event $event): void {
		if (!($event instanceof UserLoggedInEvent)) {
			return;
		}

		$user = $event->getUser();
		if ($user === null) {
			return;
		}

		$this->session->set('snappymail-nc-uid', $user->getUID());
	}
}
