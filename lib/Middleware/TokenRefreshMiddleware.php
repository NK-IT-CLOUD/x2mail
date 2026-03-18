<?php

declare(strict_types=1);

namespace OCA\X2Mail\Middleware;

use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\ISession;
use OCP\IUserSession;

/**
 * Delegate to user_oidc TokenService for OIDC token refresh.
 *
 * IMPORTANT: Do NOT import any OCA\UserOIDC classes here.
 * We use \OCP\Server::get() with string class names to avoid autoload interference
 * when user_oidc is not installed.
 */
class TokenRefreshMiddleware extends Middleware {
	private ISession $session;
	private IUserSession $userSession;

	public function __construct(ISession $session, IUserSession $userSession) {
		$this->session = $session;
		$this->userSession = $userSession;
	}

	public function beforeController($controller, string $methodName): void {
		// Only refresh tokens for OIDC sessions
		if (!$this->session->exists('is_oidc') || !$this->userSession->isLoggedIn()) {
			return;
		}

		// Check if user_oidc app is available
		$tokenServiceClass = 'OCA\\UserOIDC\\Service\\TokenService';
		if (!class_exists($tokenServiceClass)) {
			return;
		}

		try {
			$tokenService = \OCP\Server::get($tokenServiceClass);
			if (method_exists($tokenService, 'refreshToken')) {
				$refreshed = $tokenService->refreshToken();
				if ($refreshed && is_array($refreshed) && isset($refreshed['access_token'])) {
					$this->session->set('oidc_access_token', $refreshed['access_token']);
				}
			}
		} catch (\Throwable $e) {
			// Token refresh is best-effort; if it fails, the existing token may still work
			\OC::$server->get(\Psr\Log\LoggerInterface::class)->debug(
				'X2Mail: OIDC token refresh failed: ' . $e->getMessage()
			);
		}
	}
}
