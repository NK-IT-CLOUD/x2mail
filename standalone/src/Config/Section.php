<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Config;

/**
 * Typed, path-aware reader over one TOML table. Every key read is recorded, so
 * assertComplete() can reject typos instead of silently ignoring them.
 */
final class Section
{
    /** @var array<string, true> */
    private array $read = [];

    /** @var list<self> */
    private array $children = [];

    /** @param array<string, mixed> $data */
    public function __construct(private array $data, private string $path)
    {
    }

    public function key(string $name): string
    {
        return $this->path === '' ? $name : $this->path . '.' . $name;
    }

    private function raw(string $name): mixed
    {
        $this->read[$name] = true;
        return $this->data[$name] ?? null;
    }

    public function section(string $name): self
    {
        return $this->optionalSection($name) ?? throw new ConfigException($this->key($name) . ': missing table');
    }

    public function optionalSection(string $name): ?self
    {
        $value = $this->raw($name);
        if ($value === null) {
            return null;
        }
        if (!\is_array($value) || ($value !== [] && \array_is_list($value))) {
            throw new ConfigException($this->key($name) . ': must be a table');
        }
        $child = new self($value, $this->key($name));
        $this->children[] = $child;
        return $child;
    }

    /** @return array<string, self> */
    public function sections(string $name, bool $optional = false): array
    {
        $table = $optional ? $this->optionalSection($name) : $this->section($name);
        if ($table === null) {
            return [];
        }
        $result = [];
        foreach (\array_keys($table->data) as $childName) {
            $result[(string) $childName] = $table->section((string) $childName);
        }
        return $result;
    }

    public function string(string $name, ?string $default = null): string
    {
        $value = $this->raw($name);
        if ($value === null && $default !== null) {
            return $default;
        }
        if (!\is_string($value) || \trim($value) === '') {
            throw new ConfigException($this->key($name) . ': must be a non-empty string');
        }
        return $value;
    }

    public function optionalString(string $name): ?string
    {
        return $this->raw($name) === null ? null : $this->string($name);
    }

    public function bool(string $name, bool $default): bool
    {
        $value = $this->raw($name);
        if ($value === null) {
            return $default;
        }
        if (!\is_bool($value)) {
            throw new ConfigException($this->key($name) . ': must be true or false');
        }
        return $value;
    }

    public function int(string $name, int $default, int $min, int $max): int
    {
        $value = $this->raw($name);
        if ($value === null) {
            return $default;
        }
        if (!\is_int($value) || $value < $min || $value > $max) {
            throw new ConfigException($this->key($name) . ": must be an integer between {$min} and {$max}");
        }
        return $value;
    }

    /**
     * @param list<string> $default
     * @return list<string>
     */
    public function stringList(string $name, array $default): array
    {
        $value = $this->raw($name);
        if ($value === null) {
            return $default;
        }
        if (!\is_array($value) || !\array_is_list($value)) {
            throw new ConfigException($this->key($name) . ': must be a list of strings');
        }
        foreach ($value as $item) {
            if (!\is_string($item) || $item === '') {
                throw new ConfigException($this->key($name) . ': must be a list of strings');
            }
        }
        return $value;
    }

    public function httpsUrl(string $name): string
    {
        $value = $this->string($name);
        $parts = \parse_url(\str_replace('{tenant}', 'tenant', $value));
        if (!\is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            throw new ConfigException($this->key($name) . ': must be an https:// URL');
        }
        return $value;
    }

    public function assertComplete(): void
    {
        foreach (\array_keys($this->data) as $name) {
            if (!isset($this->read[(string) $name])) {
                throw new ConfigException($this->key((string) $name) . ': unknown key');
            }
        }
        foreach ($this->children as $child) {
            $child->assertComplete();
        }
    }
}
