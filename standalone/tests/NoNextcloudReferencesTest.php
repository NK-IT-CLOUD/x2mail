<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Tests;

use PHPUnit\Framework\TestCase;

/** src/, public/, bin/ and the engine plugin must run without Nextcloud. */
class NoNextcloudReferencesTest extends TestCase
{
    public function testNoOcpOrOcaNames(): void
    {
        $root = \dirname(__DIR__);
        $found = [];
        $scanned = 0;
        foreach (['src', 'public', 'bin', 'engine-plugin'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/{$dir}", \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $isPhp = $file->getExtension() === 'php' || \str_starts_with((string) \file_get_contents($file->getPathname(), false, null, 0, 20), '#!/usr/bin/env php');
                if (!$isPhp) {
                    continue;
                }
                $scanned++;
                foreach (\token_get_all((string) \file_get_contents($file->getPathname())) as $t) {
                    if (\is_array($t) && \in_array($t[0], [\T_NAME_FULLY_QUALIFIED, \T_NAME_QUALIFIED, \T_STRING], true)) {
                        $name = '\\' . \ltrim($t[1], '\\');
                        if (\str_starts_with($name, '\\OCP\\') || \str_starts_with($name, '\\OCA\\') || \in_array($name, ['\\OC', '\\OCP', '\\OCA'], true)) {
                            $found[] = \substr($file->getPathname(), \strlen($root) + 1) . ":{$t[2]} {$name}";
                        }
                    }
                }
            }
        }
        self::assertGreaterThan(20, $scanned);
        self::assertSame([], $found, \implode("\n", $found));
    }
}
