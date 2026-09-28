<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Tests\Access;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use X2Mail\Standalone\Access\RuleEvaluator;
use X2Mail\Standalone\Config\Rule;

class RuleEvaluatorTest extends TestCase
{
    /**
     * @param list<string> $anyOf
     * @param list<string> $allOf
     */
    private static function rule(string $claim, array $anyOf = [], array $allOf = [], bool $strip = true): Rule
    {
        return new Rule('r', $claim, $anyOf, $allOf, $strip);
    }

    /** @return iterable<string, array{Rule, array<string, mixed>, bool}> */
    public static function cases(): iterable
    {
        yield 'groups any_of hit' => [self::rule('groups', ['mailxyz']), ['groups' => ['a', 'mailxyz']], true];
        yield 'groups any_of miss' => [self::rule('groups', ['mailxyz']), ['groups' => ['a', 'b']], false];
        yield 'realm roles nested' => [self::rule('realm_access.roles', ['mailxyz']), ['realm_access' => ['roles' => ['mailxyz']]], true];
        yield 'client roles nested' => [self::rule('resource_access.webmail.roles', ['abz']), ['resource_access' => ['webmail' => ['roles' => ['abz']]]], true];
        yield 'all_of complete' => [self::rule('groups', [], ['a', 'b']), ['groups' => ['b', 'a', 'c']], true];
        yield 'all_of one missing' => [self::rule('groups', [], ['a', 'b']), ['groups' => ['a']], false];
        yield 'any_of and all_of both met' => [self::rule('groups', ['x', 'a'], ['b']), ['groups' => ['a', 'b']], true];
        yield 'any_of met, all_of not' => [self::rule('groups', ['a'], ['b']), ['groups' => ['a']], false];
        yield 'string claim' => [self::rule('role', ['mailxyz']), ['role' => 'mailxyz'], true];
        yield 'missing claim' => [self::rule('groups', ['mailxyz']), [], false];
        yield 'int claim' => [self::rule('groups', ['1']), ['groups' => 1], false];
        yield 'map claim' => [self::rule('groups', ['mailxyz']), ['groups' => ['mailxyz' => true]], false];
        yield 'empty list' => [self::rule('groups', [], ['a']), ['groups' => []], false];
        yield 'path through scalar' => [self::rule('realm_access.roles', ['x']), ['realm_access' => 'x'], false];
        yield 'strip leading slash' => [self::rule('groups', ['mailxyz']), ['groups' => ['/mailxyz']], true];
        yield 'no strip keeps slash' => [self::rule('groups', ['mailxyz'], [], false), ['groups' => ['/mailxyz']], false];
        yield 'strip only one leading slash' => [self::rule('groups', ['parent/child']), ['groups' => ['/parent/child']], true];
        yield 'case sensitive' => [self::rule('groups', ['mailxyz']), ['groups' => ['MailXYZ']], false];
        yield 'list with non-string' => [self::rule('groups', ['mailxyz']), ['groups' => ['mailxyz', 5]], false];
    }

    /** @param array<string, mixed> $claims */
    #[DataProvider('cases')]
    public function testMatches(Rule $rule, array $claims, bool $expected): void
    {
        self::assertSame($expected, (new RuleEvaluator())->matches($rule, $claims));
    }
}
