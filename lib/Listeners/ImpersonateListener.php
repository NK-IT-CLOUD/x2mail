<?php

declare(strict_types=1);

namespace OCA\X2Mail\Listeners;

use OCA\X2Mail\Service\LogService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\ISession;

/** @implements IEventListener<Event> */
class ImpersonateListener implements IEventListener
{
    public function __construct(
        private ISession $session,
        private LogService $logService,
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

        $this->session->remove('x2mail-passphrase');
        $this->session->remove('x2mail-uid');
        $this->logService->debug("Session cleared on impersonate: {$class}");
    }
}
