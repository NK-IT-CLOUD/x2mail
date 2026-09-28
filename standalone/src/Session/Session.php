<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Session;

interface Session
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /** New session id, data kept (after login: against fixation). */
    public function regenerate(): void;

    /** Empties the session and issues a new id; the session stays usable in this request. */
    public function destroy(): void;

    public function id(): string;
}
