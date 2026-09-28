<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Session;

/**
 * Native PHP session. The files handler locks the session file for the whole
 * request — that lock is what serialises token refreshes of one session.
 */
final class PhpSession implements Session
{
    public const COOKIE = '__Host-x2w';

    private bool $lazy = false;

    public static function start(): self
    {
        self::configure();
        if (!\session_start(['use_strict_mode' => true, 'use_only_cookies' => true])) {
            throw new \RuntimeException('session could not be started');
        }
        return new self();
    }

    /** Defers session_start() until the first use (get/set/remove/regenerate/destroy/id). */
    public static function lazy(): self
    {
        $session = new self();
        $session->lazy = true;
        return $session;
    }

    private static function configure(): void
    {
        \session_name(self::COOKIE);
        \session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'domain' => '', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    }

    private function ensureStarted(): void
    {
        if (!$this->lazy) {
            return;
        }
        $this->lazy = false;
        self::configure();
        if (!\session_start(['use_strict_mode' => true, 'use_only_cookies' => true])) {
            throw new \RuntimeException('session could not be started');
        }
    }

    public function get(string $key): mixed
    {
        $this->ensureStarted();
        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->ensureStarted();
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        $this->ensureStarted();
        if (!@\session_regenerate_id(true)) {
            throw new \RuntimeException('session id could not be regenerated');
        }
    }

    public function destroy(): void
    {
        $this->ensureStarted();
        $_SESSION = [];
        if (!@\session_regenerate_id(true)) {
            throw new \RuntimeException('session id could not be regenerated');
        }
    }

    public function id(): string
    {
        $this->ensureStarted();
        return (string) \session_id();
    }

    /** Releases the lock before the engine runs its (long) request. */
    public function close(): void
    {
        \session_write_close();
    }
}
