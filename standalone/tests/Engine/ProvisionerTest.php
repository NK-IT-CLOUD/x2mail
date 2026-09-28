<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Tests\Engine;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use X2Mail\Standalone\Config\Config;
use X2Mail\Standalone\Engine\Provisioner;

/**
 * Runs the real engine bootstrap in API mode against a temp data dir. Separate
 * process: the engine defines global constants.
 */
class ProvisionerTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testProvisionsDomainPluginAndDefaults(): void
    {
        $root = \dirname(__DIR__, 3);
        $dir = \sys_get_temp_dir() . '/x2w-prov-' . \bin2hex(\random_bytes(4));
        \mkdir($dir . '/data', 0700, true);
        \file_put_contents($dir . '/secret', 's');

        $config = Config::fromArray([
            'webmail' => ['base_url' => 'https://webmail.example.org', 'data_dir' => $dir . '/data'],
            'oidc' => ['issuer' => 'https://id.example.org/realms/central', 'client_id' => 'webmail', 'client_secret_file' => $dir . '/secret'],
            'mail' => ['host' => 'mail.example.org'],
            'rules' => ['mail_user' => ['claim' => 'groups', 'any_of' => ['mail']]],
            'access' => ['login_requires' => 'mail_user'],
        ]);

        try {
            (new Provisioner($config, $root . '/app/index.php', $root . '/standalone/engine-plugin/standalone'))->run();

            $engineData = $dir . '/data/engine/_data_/_default_/';
            $domain = \json_decode((string) \file_get_contents($engineData . 'domains/mail.example.org.json'), true);
            self::assertSame('mail.example.org', $domain['IMAP']['host']);
            self::assertFileExists($engineData . 'plugins/standalone/index.php');

            $oConfig = \X2Mail\Engine\Api::Config();
            self::assertSame('mail.example.org', $oConfig->Get('login', 'default_domain'));
            self::assertSame('/', $oConfig->Get('webmail', 'app_path'));
            self::assertTrue((bool) $oConfig->Get('plugins', 'enable'));
            self::assertContains('standalone', \array_map('trim', \explode(',', (string) $oConfig->Get('plugins', 'enabled_list'))));
            self::assertSame('x2mail', $oConfig->Get('webmail', 'theme'));

            // No per-domain config: every mail domain falls back to login.default_domain
            // (0.8.2; the fallback itself is covered by AccountFromNcSessionTest).
            self::assertDirectoryExists($dir . '/data/cache');
            self::assertNull(\X2Mail\Engine\Api::Actions()->DomainProvider()->Load('kunde-b.de'));
            self::assertNotNull(\X2Mail\Engine\Api::Actions()->DomainProvider()->Load('mail.example.org'));

            // Idempotent: a second run must not fail or duplicate the plugin entry.
            (new Provisioner($config, $root . '/app/index.php', $root . '/standalone/engine-plugin/standalone'))->run();
            $list = \array_map('trim', \explode(',', (string) $oConfig->Get('plugins', 'enabled_list')));
            self::assertSame(1, \count(\array_keys($list, 'standalone', true)));
        } finally {
            self::removeTree($dir);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testThrowsWhenPluginSourceIsMissing(): void
    {
        $root = \dirname(__DIR__, 3);
        $dir = \sys_get_temp_dir() . '/x2w-prov-' . \bin2hex(\random_bytes(4));
        \mkdir($dir . '/data', 0700, true);
        \file_put_contents($dir . '/secret', 's');

        $config = $this->buildConfig($dir, 'mail.example.org');
        $missingPluginSource = $dir . '/no-such-plugin-source';

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage($missingPluginSource);
            (new Provisioner($config, $root . '/app/index.php', $missingPluginSource))->run();
        } finally {
            self::removeTree($dir);
        }
    }

    /**
     * Root-proof variant of the plugin-copy-failure finding: instead of an
     * unreadable source file (root reads those anyway), block the *target*
     * path with a plain file so installPlugin()'s mkdir() for it fails.
     * Exercises the same ignored-return-value defect without depending on
     * process UID.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testThrowsWhenPluginTargetCannotBeCreated(): void
    {
        $root = \dirname(__DIR__, 3);
        $dir = \sys_get_temp_dir() . '/x2w-prov-' . \bin2hex(\random_bytes(4));
        \mkdir($dir . '/data', 0700, true);
        \file_put_contents($dir . '/secret', 's');

        $pluginsDir = $dir . '/data/engine/_data_/_default_/plugins';
        \mkdir($pluginsDir, 0750, true);
        \file_put_contents($pluginsDir . '/standalone', 'not a directory');

        $config = $this->buildConfig($dir, 'mail.example.org');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage($pluginsDir . '/standalone');
            (new Provisioner($config, $root . '/app/index.php', $root . '/standalone/engine-plugin/standalone'))->run();
        } finally {
            self::removeTree($dir);
        }
    }

    /**
     * Sub-case of the plugin-copy-failure finding: a plugin source file that
     * exists but cannot be read. Skipped when running as root — root reads
     * mode-0000 files regardless, so copy() cannot be made to fail this way
     * (documented in task-6-report.md).
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testThrowsWhenAPluginFileCannotBeCopied(): void
    {
        if (0 === \posix_getuid()) {
            self::markTestSkipped('running as root: mode 0000 is still readable, cannot force copy() to fail this way');
        }

        $root = \dirname(__DIR__, 3);
        $dir = \sys_get_temp_dir() . '/x2w-prov-' . \bin2hex(\random_bytes(4));
        \mkdir($dir . '/data', 0700, true);
        \file_put_contents($dir . '/secret', 's');

        $pluginSource = $dir . '/plugin-src';
        \mkdir($pluginSource, 0755, true);
        \file_put_contents($pluginSource . '/index.php', '<?php');
        \file_put_contents($pluginSource . '/unreadable.php', '<?php');
        \chmod($pluginSource . '/unreadable.php', 0000);

        $config = $this->buildConfig($dir, 'mail.example.org');

        try {
            $this->expectException(\RuntimeException::class);
            (new Provisioner($config, $root . '/app/index.php', $pluginSource))->run();
        } finally {
            \chmod($pluginSource . '/unreadable.php', 0644);
            self::removeTree($dir);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRejectsBadHostBeforeSavingConfig(): void
    {
        $root = \dirname(__DIR__, 3);
        $dir = \sys_get_temp_dir() . '/x2w-prov-' . \bin2hex(\random_bytes(4));
        \mkdir($dir . '/data', 0700, true);
        \file_put_contents($dir . '/secret', 's');

        $config = $this->buildConfig($dir, 'bad/host');

        try {
            try {
                (new Provisioner($config, $root . '/app/index.php', $root . '/standalone/engine-plugin/standalone'))->run();
                self::fail('expected a RuntimeException for the invalid mail.host');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('bad/host', $e->getMessage());
            }

            // The bad host must never reach application.ini — validation
            // happens before bootEngine()/Set()/Save(), not just in
            // writeDomain(). If the engine dir was created at all, its
            // config must not mention the bad host.
            $iniPath = $dir . '/data/engine/_data_/_default_/configs/application.ini';
            if (\is_file($iniPath)) {
                self::assertStringNotContainsString('bad/host', (string) \file_get_contents($iniPath));
            }
        } finally {
            self::removeTree($dir);
        }
    }

    private function buildConfig(string $dir, string $host): Config
    {
        return Config::fromArray([
            'webmail' => ['base_url' => 'https://webmail.example.org', 'data_dir' => $dir . '/data'],
            'oidc' => ['issuer' => 'https://id.example.org/realms/central', 'client_id' => 'webmail', 'client_secret_file' => $dir . '/secret'],
            'mail' => ['host' => $host],
            'rules' => ['mail_user' => ['claim' => 'groups', 'any_of' => ['mail']]],
            'access' => ['login_requires' => 'mail_user'],
        ]);
    }

    private static function removeTree(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? \rmdir($item->getPathname()) : \unlink($item->getPathname());
        }
        \rmdir($dir);
    }
}
