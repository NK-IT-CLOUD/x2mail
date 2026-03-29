<?php

declare(strict_types=1);

namespace OCA\X2Mail\Service;

use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserSession;

/**
 * X2Mail debug logger — writes to appdata_x2mail/x2mail.log
 * Independent from NC log level. Enable via:
 *   occ config:app:set x2mail debug_log --value=1
 *   or Admin -> X2Mail -> Enable debug logging
 */
class LogService
{
    private const APP_ID = 'x2mail';
    private ?bool $enabled = null;
    private ?string $logFile = null;

    public function __construct(
        private IAppConfig $appConfig,
        private IConfig $config,
        private IUserSession $userSession,
    ) {
    }

    public function isEnabled(): bool
    {
        if ($this->enabled === null) {
            try {
                $this->enabled = $this->appConfig->getValueString(self::APP_ID, 'debug_log', '0') === '1';
            } catch (\Throwable $e) {
                $this->enabled = false;
            }
        }
        return $this->enabled;
    }

    public function enable(): void
    {
        $this->appConfig->setValueString(self::APP_ID, 'debug_log', '1');
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->appConfig->setValueString(self::APP_ID, 'debug_log', '0');
        $this->enabled = false;
    }

    private function getLogFile(): string
    {
        if ($this->logFile === null) {
            $dataDir = \rtrim(\trim($this->config->getSystemValue('datadirectory', '')), '\\/');
            $logDir = $dataDir . '/appdata_x2mail';
            if (!\is_dir($logDir)) {
                @\mkdir($logDir, 0750, true);
            }
            $this->logFile = $logDir . '/x2mail.log';
        }
        return $this->logFile;
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->write('DEBUG', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->write('WARN', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context, true);
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context = [], bool $force = false): void
    {
        if (!$force && !$this->isEnabled()) {
            return;
        }

        $timestamp = \date('Y-m-d H:i:s');
        $user = '-';
        try {
            $u = $this->userSession->getUser();
            $user = $u ? $u->getUID() : '-';
        } catch (\Throwable $e) {
            // no session context (CLI, etc.)
        }

        $line = "[{$timestamp}] [{$level}] [{$user}] {$message}";
        if (!empty($context)) {
            $line .= ' ' . \json_encode($context, \JSON_UNESCAPED_SLASHES);
        }
        $line .= "\n";

        $logFile = $this->getLogFile();
        $isNew = !\file_exists($logFile);
        @\file_put_contents($logFile, $line, \FILE_APPEND | \LOCK_EX);
        if ($isNew) {
            @\chmod($logFile, 0600);
        }
    }

    public function tail(int $lines = 50): string
    {
        $file = $this->getLogFile();
        if (!\file_exists($file)) {
            return '(no log file)';
        }
        $content = \file_get_contents($file);
        if ($content === false) {
            return '(read error)';
        }
        $allLines = \explode("\n", \rtrim($content));
        $slice = \array_slice($allLines, -$lines);
        return \implode("\n", $slice);
    }

    public function clear(): void
    {
        $file = $this->getLogFile();
        if (\file_exists($file)) {
            \file_put_contents($file, '');
        }
    }
}
