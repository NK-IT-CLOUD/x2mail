<?php

declare(strict_types=1);

namespace OCA\X2Mail\Listeners;

use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\ISession;
use OCP\IUserSession;

class AccessTokenUpdatedListener implements IEventListener {
	private IUserSession $userSession;
	private ISession $session;
	private IAppManager $appManager;

	private const X2MAIL_APP_ID = 'x2mail';
	private const OIDC_LOGIN_APP_ID = 'oidc_login';

	public function __construct(IUserSession $userSession, ISession $session, IAppManager $appManager) {
		$this->userSession = $userSession;
		$this->session = $session;
		$this->appManager = $appManager;
	}

	public function handle(Event $event): void {
		// Use string check instead of instanceof to avoid autoload interference
		$eventClass = get_class($event);
		if ($eventClass !== 'OCA\\OIDCLogin\\Events\\AccessTokenUpdatedEvent') {
			return;
		}
		if (!$this->userSession->isLoggedIn() || !$this->session->exists('is_oidc')) {
			return;
		}
		if (!$this->appManager->isEnabledForUser(self::X2MAIL_APP_ID) || !$this->appManager->isEnabledForUser(self::OIDC_LOGIN_APP_ID)) {
			return;
		}
		$accessToken = $event->getAccessToken();
		if (!$accessToken) {
			return;
		}

		$username = $this->userSession->getUser()->getUID();
		$this->session->set('snappymail-nc-uid', $username);
	}
}
