<?php

declare(strict_types=1);

namespace OCA\X2Mail\Middleware;

use OCA\X2Mail\Service\LogService;
use OCP\AppFramework\Middleware;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ISession;

/**
 * Auto-refresh OIDC token via user_oidc's public ExternalTokenRequestedEvent.
 * Requires user_oidc store_login_token=1. user_oidc handles refresh + locking
 * internally; we only pull the (fresh) token and sync it to our session key.
 *
 * IMPORTANT: Do NOT import any OCA\UserOIDC classes — resolved at runtime.
 */
class TokenRefreshMiddleware extends Middleware
{
    private bool $synced = false;

    public function __construct(
        private ISession $session,
        private IEventDispatcher $eventDispatcher,
        private LogService $logService,
    ) {
    }

    public function beforeController($controller, string $methodName): void
    {
        if ($this->synced || !$this->session->get('is_oidc')) {
            return;
        }
        $this->synced = true;

        $eventClass = 'OCA\\UserOIDC\\Event\\ExternalTokenRequestedEvent';
        if (!\class_exists($eventClass)) {
            return; // user_oidc not installed
        }

        try {
            $event = new $eventClass();
            if (!$event instanceof Event) {
                return;
            }
            $this->eventDispatcher->dispatchTyped($event);

            if (!\method_exists($event, 'getToken')) {
                return;
            }
            $token = $event->getToken();
            if (!\is_object($token) || !\method_exists($token, 'getAccessToken')) {
                return;
            }
            $freshToken = $token->getAccessToken();
            $current = $this->session->get('oidc_access_token');
            if ($freshToken && $freshToken !== $current) {
                $this->session->set('oidc_access_token', $freshToken);
                $this->logService->debug('OIDC token refreshed via ExternalTokenRequestedEvent');
            }
        } catch (\Throwable $e) {
            $this->logService->warning('Token refresh skipped: ' . $e->getMessage());
        }
    }
}
