<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Tests\Config;

use PHPUnit\Framework\TestCase;
use X2Mail\Standalone\Config\ConfigException;
use X2Mail\Standalone\Config\Rule;

class RuleTest extends TestCase
{
    public function testRuleWithoutAnyOfAndAllOfIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('rules.mail_user: needs any_of or all_of');
        new Rule('mail_user', 'realm_access.roles', [], [], true);
    }

    public function testRuleWithAnyOfIsAccepted(): void
    {
        $rule = new Rule('mail_user', 'realm_access.roles', ['mail'], [], true);
        self::assertSame(['mail'], $rule->anyOf);
    }
}
