<?php

declare(strict_types=1);

namespace OCA\X2Mail\Middleware;

use OCP\AppFramework\Middleware;
use OCP\ISession;
use Psr\Log\LoggerInterface;

/**
 * On every NC request, if the user has an OIDC session, delegates to
 * user_oidc's TokenService to get a (possibly refreshed) access token
 * and updates the session key that SnappyMail reads for OAUTHBEARER.
 *
 * Requires user_oidc store_login_token=1.
 *
 * IMPORTANT: Do NOT import any OCA\UserOIDC classes here.
 */
class TokenRefreshMiddleware extends Middleware {
	public function __construct(
		private ISession $session,
		private LoggerInterface $logger,
	) {}

	public function beforeController($controller, string $methodName): void {
		if (!$this->session->get('is_oidc')) {
			return;
		}

		try {
			$tokenService = \OCP\Server::get('OCA\UserOIDC\Service\TokenService');
			$token = $tokenService->getToken(); // auto-refreshes if expired

			if ($token !== null) {
				$freshToken = $token->getAccessToken();
				$current = $this->session->get('oidc_access_token');
				if ($freshToken !== $current) {
					$this->session->set('oidc_access_token', $freshToken);
					$this->logger->info('X2Mail: refreshed OIDC access token');
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('X2Mail: token refresh skipped: ' . $e->getMessage());
		}
	}
}
