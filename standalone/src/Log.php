<?php

declare(strict_types=1);

namespace X2Mail\Standalone;

/** Minimal logger. Callers pass only token-free text. */
final class Log
{
    /** @var callable(string): void */
    private $sink;

    public function __construct(?callable $sink = null)
    {
        $this->sink = $sink ?? static function (string $line): void {
            \error_log($line);
        };
    }

    public function warning(string $message): void
    {
        ($this->sink)('[x2mail-webmail] WARNING: ' . self::sanitise($message));
    }

    public function info(string $message): void
    {
        ($this->sink)('[x2mail-webmail] INFO: ' . self::sanitise($message));
    }

    private static function sanitise(string $message): string
    {
        return (string) \preg_replace('/[\x00-\x1F\x7F]/', '', \str_replace("\t", ' ', $message));
    }
}
