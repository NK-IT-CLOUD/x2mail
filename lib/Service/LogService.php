<?php

declare(strict_types=1);

namespace OCA\X2Mail\Service;

use OCP\IConfig;

/**
 * X2Mail debug logger — writes to appdata_x2mail/x2mail.log
 * Independent from NC log level. Enable via:
 *   occ config:app:set x2mail debug_log --value=1
 *   or Admin → X2Mail → Enable debug logging
 */
class LogService {
	private const APP_ID = 'x2mail';
	private static ?bool $enabled = null;
	private static ?string $logFile = null;

	public static function isEnabled(): bool {
		if (self::$enabled === null) {
			try {
				$config = \OCP\Server::get(IConfig::class);
				self::$enabled = $config->getAppValue(self::APP_ID, 'debug_log', '0') === '1';
			} catch (\Throwable $e) {
				self::$enabled = false;
			}
		}
		return self::$enabled;
	}

	public static function enable(): void {
		$config = \OCP\Server::get(IConfig::class);
		$config->setAppValue(self::APP_ID, 'debug_log', '1');
		self::$enabled = true;
	}

	public static function disable(): void {
		$config = \OCP\Server::get(IConfig::class);
		$config->setAppValue(self::APP_ID, 'debug_log', '0');
		self::$enabled = false;
	}

	private static function getLogFile(): string {
		if (self::$logFile === null) {
			$dataDir = \rtrim(\trim(\OCP\Server::get(IConfig::class)->getSystemValue('datadirectory', '')), '\\/');
			$logDir = $dataDir . '/appdata_x2mail';
			if (!\is_dir($logDir)) {
				@\mkdir($logDir, 0750, true);
			}
			self::$logFile = $logDir . '/x2mail.log';
		}
		return self::$logFile;
	}

	/** @param array<string, mixed> $context */
	public static function debug(string $message, array $context = []): void {
		self::write('DEBUG', $message, $context);
	}

	/** @param array<string, mixed> $context */
	public static function info(string $message, array $context = []): void {
		self::write('INFO', $message, $context);
	}

	/** @param array<string, mixed> $context */
	public static function warning(string $message, array $context = []): void {
		self::write('WARN', $message, $context);
	}

	/** @param array<string, mixed> $context */
	public static function error(string $message, array $context = []): void {
		// Errors always log, even if debug_log is off
		self::write('ERROR', $message, $context, true);
	}

	/** @param array<string, mixed> $context */
	private static function write(string $level, string $message, array $context = [], bool $force = false): void {
		if (!$force && !self::isEnabled()) {
			return;
		}

		$timestamp = \date('Y-m-d H:i:s');
		$user = '?';
		try {
			$userSession = \OCP\Server::get(\OCP\IUserSession::class);
			$u = $userSession->getUser();
			$user = $u ? $u->getUID() : '-';
		} catch (\Throwable $e) {
			$user = '-';
		}

		$line = "[{$timestamp}] [{$level}] [{$user}] {$message}";
		if (!empty($context)) {
			$line .= ' ' . \json_encode($context, \JSON_UNESCAPED_SLASHES);
		}
		$line .= "\n";

		$logFile = self::getLogFile();
		$isNew = !\file_exists($logFile);
		@\file_put_contents($logFile, $line, \FILE_APPEND | \LOCK_EX);
		if ($isNew) {
			@\chmod($logFile, 0600);
		}
	}

	/**
	 * Read last N lines of the log file.
	 */
	public static function tail(int $lines = 50): string {
		$file = self::getLogFile();
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

	/**
	 * Clear the log file.
	 */
	public static function clear(): void {
		$file = self::getLogFile();
		if (\file_exists($file)) {
			\file_put_contents($file, '');
		}
	}
}
