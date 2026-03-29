<?php

declare(strict_types=1);

namespace OCA\X2Mail\Listeners;

use OCA\X2Mail\Service\LogService;
use OCA\X2Mail\Util\EngineHelper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\BeforeUserLoggedOutEvent;

/**
 * Trigger engine logout on Nextcloud logout.
 */
/** @implements IEventListener<Event> */
class LogoutListener implements IEventListener
{
    public function handle(Event $event): void
    {
        if (!($event instanceof BeforeUserLoggedOutEvent)) {
            return;
        }

        try {
            EngineHelper::loadApp();
            \X2Mail\Engine\Api::Actions()->DoLogout();
        } catch (\Throwable $e) {
            LogService::warning('Engine logout failed: ' . $e->getMessage());
        }
    }
}
