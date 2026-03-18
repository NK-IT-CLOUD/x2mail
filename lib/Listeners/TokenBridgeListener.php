<?php

declare(strict_types=1);

namespace OCA\X2Mail\Listeners;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\ISession;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

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
		private LoggerInterface $logger,
	) {}

	public function handle(Event $event): void {
		if (!method_exists($event, 'getToken')) {
			return;
		}

		$tokenData = $event->getToken();
		$accessToken = $tokenData['access_token'] ?? null;

		if (!$accessToken) {
			$this->logger->warning('X2Mail: TokenObtainedEvent without access_token');
			return;
		}

		$this->session->set('oidc_access_token', $accessToken);
		$this->session->set('is_oidc', true);

		$user = $this->userSession->getUser();
		$uid = $user ? $user->getUID() : null;
		if ($uid) {
			$this->session->set('snappymail-nc-uid', $uid);
		}

		$this->logger->debug('X2Mail: stored OIDC token, uid=' . ($uid ?? 'pending'));
	}
}
