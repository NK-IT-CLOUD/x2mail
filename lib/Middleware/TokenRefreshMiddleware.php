<?php

declare(strict_types=1);

namespace OCA\X2Mail\Middleware;

use OCA\X2Mail\Service\LogService;
use OCP\AppFramework\Middleware;
use OCP\ISession;

/**
 * Auto-refresh OIDC token via user_oidc TokenService.
 * Requires user_oidc store_login_token=1.
 *
 * IMPORTANT: Do NOT import any OCA\UserOIDC classes here.
 */
class TokenRefreshMiddleware extends Middleware {
	public function __construct(
		private ISession $session,
	) {}

	public function beforeController($controller, string $methodName): void {
		if (!$this->session->get('is_oidc')) {
			return;
		}

		try {
			$tokenService = \OCP\Server::get('OCA\UserOIDC\Service\TokenService');
			$token = $tokenService->getToken(false); // don't auto-refresh yet

			if ($token === null) {
				return;
			}

			if (method_exists($token, 'isExpired') && $token->isExpired()) {
				// Token expired — trigger refresh
				$refreshed = $tokenService->getToken(true);
				if ($refreshed !== null) {
					$this->session->set('oidc_access_token', $refreshed->getAccessToken());
					$expiresIn = method_exists($refreshed, 'getExpiresInFromNow') ? $refreshed->getExpiresInFromNow() : '?';
					LogService::info("Token refreshed (expires_in={$expiresIn}s)");
				}
			} else {
				// Token still valid — just sync session if needed
				$freshToken = $token->getAccessToken();
				$current = $this->session->get('oidc_access_token');
				if ($freshToken !== $current) {
					$this->session->set('oidc_access_token', $freshToken);
				}
			}
		} catch (\Throwable $e) {
			LogService::warning('Token refresh skipped: ' . $e->getMessage());
		}
	}
}
