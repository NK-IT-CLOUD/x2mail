<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Session;

/** In-memory session for tests and CLI. */
final class ArraySession implements Session
{
    /** @var array<string, mixed> */
    private array $data = [];

    private string $id = 'array-1';

    public int $regenerations = 0;

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerate(): void
    {
        $this->regenerations++;
        $this->id = 'array-' . ($this->regenerations + 1);
    }

    public function destroy(): void
    {
        $this->data = [];
        // Change ID without counting in regenerations (destroy is not a fixation regen)
        $this->id = 'array-destroyed-' . \bin2hex(\random_bytes(4));
    }

    public function id(): string
    {
        return $this->id;
    }
}
