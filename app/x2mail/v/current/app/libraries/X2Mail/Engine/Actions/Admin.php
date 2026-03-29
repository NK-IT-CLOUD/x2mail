<?php

namespace X2Mail\Engine\Actions;

use X2Mail\Engine\Exceptions\ClientException;
use X2Mail\Engine\KeyPathHelper;
use X2Mail\Engine\Notifications;
use X2Mail\Engine\Utils;

trait Admin
{
	public function IsAdminLoggined(bool $bThrowExceptionOnFalse = true) : bool
	{
		if ($this->Config()->Get('security', 'allow_admin_panel', true)) {
			// X2Mail: delegate admin auth to Nextcloud — NC admin = engine admin
			if (\class_exists('OC') && isset(\OC::$server)) {
				try {
					$user = \OCP\Server::get(\OCP\IUserSession::class)->getUser();
					if ($user && \OCP\Server::get(\OCP\IGroupManager::class)->isAdmin($user->getUID())) {
						return true;
					}
				} catch (\Throwable $e) {
					// NC not available (e.g. CLI without user) — fall through
				}
			}
		}

		if ($bThrowExceptionOnFalse) {
			throw new ClientException(Notifications::AuthError);
		}

		return false;
	}
}
