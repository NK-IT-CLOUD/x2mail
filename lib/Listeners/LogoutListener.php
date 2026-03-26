<?php

declare(strict_types=1);

namespace OCA\X2Mail\Listeners;

use OCA\X2Mail\Service\LogService;
use OCA\X2Mail\Util\SnappyMailHelper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\BeforeUserLoggedOutEvent;

/**
 * Trigger SnappyMail logout on Nextcloud logout.
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
            SnappyMailHelper::loadApp();
            \RainLoop\Api::Actions()->DoLogout();
        } catch (\Throwable $e) {
            LogService::warning('SM logout failed: ' . $e->getMessage());
        }
    }
}
