<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Engine;

/** Loads the engine once without running it, so the front controller can ask it before handing over. */
final class EngineRunner
{
    public function __construct(
        private string $dataDir,
        private string $engineIndex,
    ) {
    }

    /** Whether the engine finds a mail account for the identity on the current Host bridge. */
    public function hasMailAccount(): bool
    {
        $this->boot();
        return \X2Mail\Engine\Api::Actions()->getMainAccountFromToken(false) !== null;
    }

    public function run(): void
    {
        $this->boot();
        \X2Mail\Engine\Service::Handle();
    }

    private function boot(): void
    {
        if (!\defined('APP_DATA_FOLDER_PATH')) {
            \define('APP_DATA_FOLDER_PATH', $this->dataDir . '/engine/');
        }
        $_ENV['X2MAIL_INCLUDE_AS_API'] = true;
        require_once $this->engineIndex;
    }
}
