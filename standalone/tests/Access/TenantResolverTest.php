<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Tests\Access;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use X2Mail\Standalone\Access\TenantResolver;
use X2Mail\Standalone\Config\Config;

class TenantResolverTest extends TestCase
{
    private function resolver(): TenantResolver
    {
        return new TenantResolver('organization', '~' . Config::DEFAULT_TENANT_PATTERN . '~D');
    }

    public function testSingleAlias(): void
    {
        $r = $this->resolver()->resolve(['organization' => ['nkit']]);
        self::assertSame('nkit', $r->tenant);
        self::assertNull($r->reason);
    }

    public function testPlainStringCountsAsOneEntry(): void
    {
        self::assertSame('kunde-a', $this->resolver()->resolve(['organization' => 'kunde-a'])->tenant);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'missing' => [[], 'missing'];
        yield 'empty list' => [['organization' => []], 'exactly one'];
        yield 'two orgs' => [['organization' => ['a', 'b']], 'exactly one'];
        yield 'map with attributes' => [['organization' => ['nkit' => ['id' => 'x']]], 'list of strings'];
        yield 'host injection' => [['organization' => ['evil.com/']], 'tenant_pattern'];
        yield 'at sign' => [['organization' => ['a@b']], 'tenant_pattern'];
        yield 'leading dash' => [['organization' => ['-nkit']], 'tenant_pattern'];
        yield 'uppercase' => [['organization' => ['NKIT']], 'tenant_pattern'];
        yield '64 chars' => [['organization' => [\str_repeat('a', 64)]], 'tenant_pattern'];
        yield 'trailing newline' => [['organization' => ["nkit\n"]], 'tenant_pattern'];
    }

    /** @param array<string, mixed> $claims */
    #[DataProvider('invalid')]
    public function testInvalidGivesReasonNotTenant(array $claims, string $reasonPart): void
    {
        $r = $this->resolver()->resolve($claims);
        self::assertNull($r->tenant);
        self::assertStringContainsString($reasonPart, (string) $r->reason);
    }
}
