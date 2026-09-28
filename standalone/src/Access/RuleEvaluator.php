<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Access;

use X2Mail\Standalone\Config\Rule;

/**
 * Decides a named rule against token claims. Anything undetermined — missing
 * claim, wrong type, empty list — is "no".
 */
final class RuleEvaluator
{
    /** @param array<string, mixed> $claims */
    public function matches(Rule $rule, array $claims): bool
    {
        $values = Claims::stringList(Claims::at($claims, $rule->claim));
        if ($values === null || $values === []) {
            return false;
        }
        if ($rule->stripPath) {
            $values = \array_map(
                static fn (string $v): string => \str_starts_with($v, '/') ? \substr($v, 1) : $v,
                $values
            );
        }

        // any_of / all_of semantics — see Step 4.
        return $this->decide($rule, $values);
    }

    /** @param list<string> $values normalised claim values, never empty */
    private function decide(Rule $rule, array $values): bool
    {
        if ($rule->anyOf !== []) {
            $found = false;
            foreach ($rule->anyOf as $needle) {
                if (\in_array($needle, $values, true)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }
        foreach ($rule->allOf as $needle) {
            if (!\in_array($needle, $values, true)) {
                return false;
            }
        }
        return true;
    }
}
