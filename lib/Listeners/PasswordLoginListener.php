<?php

declare(strict_types=1);

namespace OCA\X2Mail\Listeners;

use OCA\X2Mail\Service\LogService;
use OCA\X2Mail\Util\EngineHelper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use OCP\ISession;
use OCP\Config\IUserConfig;
use OCP\User\Events\PostLoginEvent;

/**
 * Store UID + encoded password in session on password login.
 * Skips token logins (bots, DAV clients, API).
 *
 * When plain auth is configured (autologin-oidc=0), also persists the
 * encrypted password to user config so it survives session expiry.
 */
/** @implements IEventListener<Event> */
class PasswordLoginListener implements IEventListener
{
    public function __construct(
        private ISession $session,
        private IAppConfig $appConfig,
        private IUserConfig $userConfig,
        private LogService $logService,
        private EngineHelper $engineHelper,
    ) {
    }

    public function handle(Event $event): void
    {
        if (!($event instanceof PostLoginEvent)) {
            return;
        }

        if ($event->isTokenLogin()) {
            return;
        }

        $uid = $event->getUser()->getUID();
        $password = $event->getPassword();
        $this->session->set('x2mail-uid', $uid);
        $this->session->set(
            'x2mail-passphrase',
            $this->engineHelper->encodePassword($password, $uid)
        );

        // Persist credentials when plain auth is configured (not SSO)
        $isOidc = $this->appConfig->getValueString('x2mail', 'autologin-oidc', '0') !== '0';
        if (!$isOidc) {
            $email = $this->userConfig->getValueString($uid, 'settings', 'email');
            if ($email) {
                $this->userConfig->setValueString(
                    $uid,
                    'x2mail',
                    'email',
                    $email
                );
                $this->userConfig->setValueString(
                    $uid,
                    'x2mail',
                    'passphrase',
                    $this->engineHelper->encodePassword($password, \md5($email))
                );
                $this->logService->debug("Password persisted for uid={$uid}");
            }
        }

        $this->logService->debug("Password login: uid={$uid}");
    }
}
