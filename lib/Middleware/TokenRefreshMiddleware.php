<?php

declare(strict_types=1);

namespace OCA\X2Mail\Middleware;

use OCA\X2Mail\Service\LogService;
use OCP\AppFramework\Middleware;
use OCP\ISession;
use Psr\Container\ContainerInterface;

/**
 * Auto-refresh OIDC token via user_oidc TokenService.
 * Requires user_oidc store_login_token=1.
 *
 * Strategy: Call getToken(true) which auto-refreshes when expired.
 * Then sync the (possibly new) access token to oidc_access_token session key.
 * Only runs once per request via the $synced flag.
 *
 * Note: user_oidc may report expires_in=0 due to client-level token lifespan
 * override being 0 (= use realm default). This causes isExpired()/isExpiring()
 * to be unreliable. We delegate refresh decisions entirely to TokenService.
 *
 * IMPORTANT: Do NOT import any OCA\UserOIDC classes here — resolved at runtime.
 */
class TokenRefreshMiddleware extends Middleware
{
    private bool $synced = false;

    public function __construct(
        private ISession $session,
        private ContainerInterface $container,
        private LogService $logService,
    ) {
    }

    public function beforeController($controller, string $methodName): void
    {
        if ($this->synced || !$this->session->get('is_oidc')) {
            return;
        }
        $this->synced = true;

        try {
            $tokenService = $this->container->get('OCA\UserOIDC\Service\TokenService');
            // getToken(true) handles refresh internally — returns fresh token if expired
            $token = $tokenService->getToken(true);

            if ($token === null) {
                return;
            }

            $freshToken = $token->getAccessToken();
            $current = $this->session->get('oidc_access_token');

            if ($freshToken !== $current) {
                $this->session->set('oidc_access_token', $freshToken);
                $this->logService->debug('Token synced to session');
            }
        } catch (\Throwable $e) {
            $this->logService->warning('Token refresh skipped: ' . $e->getMessage());
        }
    }
}
