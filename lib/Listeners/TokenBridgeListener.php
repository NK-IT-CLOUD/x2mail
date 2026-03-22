<?php

declare(strict_types=1);

namespace OCA\X2Mail\Listeners;

use OCA\X2Mail\Service\LogService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\ISession;
use OCP\IUserSession;

/**
 * Bridge user_oidc TokenObtainedEvent to SnappyMail session keys.
 *
 * IMPORTANT: Do NOT import any OCA\UserOIDC classes here.
 */
/** @implements IEventListener<Event> */
class TokenBridgeListener implements IEventListener {
	public function __construct(
		private IUserSession $userSession,
		private ISession $session,
	) {}

	public function handle(Event $event): void {
		if (!method_exists($event, 'getToken')) {
			return;
		}

		$tokenData = $event->getToken();
		$accessToken = $tokenData['access_token'] ?? null;

		if (!$accessToken) {
			LogService::warning('TokenObtainedEvent without access_token');
			return;
		}

		$this->session->set('oidc_access_token', $accessToken);
		$this->session->set('is_oidc', true);

		$user = $this->userSession->getUser();
		$uid = $user ? $user->getUID() : null;
		if ($uid) {
			$this->session->set('snappymail-nc-uid', $uid);
		}

		$tokenLen = \strlen($accessToken);
		LogService::info("OIDC token stored (len={$tokenLen}), uid=" . ($uid ?? 'pending'));
	}
}
