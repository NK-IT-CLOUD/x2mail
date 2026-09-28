<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Config;

/** A named claim rule: `claim` is a dot path into the token claims. */
final class Rule
{
    /**
     * @param list<string> $anyOf
     * @param list<string> $allOf
     */
    public function __construct(
        public readonly string $name,
        public readonly string $claim,
        public readonly array $anyOf,
        public readonly array $allOf,
        public readonly bool $stripPath,
    ) {
        if ($anyOf === [] && $allOf === []) {
            throw new ConfigException("rules.{$name}: needs any_of or all_of");
        }
    }
}
