<?php

declare(strict_types=1);

namespace OCA\X2Mail\Listeners;

use OCA\X2Mail\Service\LogService;
use OCA\X2Mail\Util\EngineHelper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\ISession;

/**
 * Clear passphrase + engine logout on impersonate begin/end.
 *
 * Handles both BeginImpersonateEvent and EndImpersonateEvent.
 * Uses string class check to avoid hard dependency on the impersonate app.
 *
 * @see https://github.com/nextcloud/impersonate/issues/179
 */
/** @implements IEventListener<Event> */
class ImpersonateListener implements IEventListener
{
    public function __construct(
        private ISession $session,
    ) {
    }

    public function handle(Event $event): void
    {
        $class = get_class($event);
        if (
            $class !== 'OCA\\Impersonate\\Events\\BeginImpersonateEvent'
            && $class !== 'OCA\\Impersonate\\Events\\EndImpersonateEvent'
        ) {
            return;
        }

        $this->session->set('x2mail-passphrase', '');

        try {
            EngineHelper::loadApp();
            \X2Mail\Engine\Api::Actions()->Logout(true);
        } catch (\Throwable $e) {
            LogService::warning('Engine impersonate logout failed: ' . $e->getMessage());
        }

        LogService::debug("Impersonate event: {$class}");
    }
}
