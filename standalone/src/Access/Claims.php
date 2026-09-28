<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Access;

/** Read-only helpers over decoded token claims (arrays, not stdClass). */
final class Claims
{
    /** @param array<string, mixed> $claims */
    public static function at(array $claims, string $path): mixed
    {
        $node = $claims;
        foreach (\explode('.', $path) as $segment) {
            if (!\is_array($node) || !\array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }
        return $node;
    }

    /** @return list<string>|null null when the value is not a string or a list of strings */
    public static function stringList(mixed $value): ?array
    {
        if (\is_string($value)) {
            return [$value];
        }
        if (!\is_array($value) || !\array_is_list($value)) {
            return null;
        }
        foreach ($value as $item) {
            if (!\is_string($item)) {
                return null;
            }
        }
        /** @var list<string> $value */
        return $value;
    }
}
