<?php

declare(strict_types=1);

namespace OCA\X2Mail\Listeners;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\ISession;
use OCP\IUserSession;

/**
 * Bridge user_oidc TokenObtainedEvent to SnappyMail session keys.
 *
 * IMPORTANT: Do NOT import any OCA\UserOIDC classes here.
 * This avoids autoload interference when user_oidc is not installed.
 */
class TokenBridgeListener implements IEventListener {
	public function __construct(
		private IUserSession $userSession,
		private ISession $session,
	) {}

	public function handle(Event $event): void {
		// Use method_exists — no class imports, no instanceof, no autoload issues
		if (!method_exists($event, 'getToken')) {
			return;
		}

		$tokenData = $event->getToken();
		$accessToken = $tokenData['access_token'] ?? null;

		if (!$accessToken) {
			return;
		}

		// Set session keys that SnappyMail's nextcloud plugin reads
		$this->session->set('oidc_access_token', $accessToken);
		$this->session->set('is_oidc', true);

		// User may not be logged in yet at TokenObtainedEvent time
		$user = $this->userSession->getUser();
		if ($user) {
			$this->session->set('snappymail-nc-uid', $user->getUID());
		}
	}
}
