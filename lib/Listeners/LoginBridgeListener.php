<?php

declare(strict_types=1);

namespace OCA\X2Mail\Listeners;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\ISession;
use OCP\User\Events\UserLoggedInEvent;
use Psr\Log\LoggerInterface;

/**
 * Set snappymail-nc-uid on UserLoggedInEvent.
 * Also sets snappymail-passphrase for OIDC sessions.
 */
class LoginBridgeListener implements IEventListener {
	public function __construct(
		private ISession $session,
		private LoggerInterface $logger,
	) {}

	public function handle(Event $event): void {
		if (!($event instanceof UserLoggedInEvent)) {
			return;
		}

		$user = $event->getUser();
		if ($user === null) {
			return;
		}

		$uid = $user->getUID();
		$this->session->set('snappymail-nc-uid', $uid);

		if ($this->session->get('is_oidc')) {
			$this->session->set('snappymail-passphrase', 'oidc_token_bridge');
			$this->logger->info('X2Mail: LoginBridge uid=' . $uid . ' is_oidc=true');
		}
	}
}
