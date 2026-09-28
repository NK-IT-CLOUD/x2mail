<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Config;

/** One mail host for every tenant; the mailproxy routes by token. */
final class MailSettings
{
    public function __construct(
        public readonly string $host,
        public readonly int $imapPort,
        public readonly int $smtpPort,
        public readonly int $sievePort,
    ) {
    }
}
