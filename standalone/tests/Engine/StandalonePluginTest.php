<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Tests\Engine;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use X2Mail\Standalone\Config\Config;
use X2Mail\Standalone\Engine\Provisioner;

/**
 * Runs the provisioned engine plugin through the engine's own app-data
 * filter. Separate process: the engine defines global constants.
 */
class StandalonePluginTest extends TestCase
{
    /**
     * The engine's idle timer calls the client logout, which the plugin points
     * at /oidc/logout — an RP-initiated logout that ends the SSO session for
     * every app. The plugin must switch the timer off, also over a per-user
     * value (the 30 here): Actions::AppData merges the user settings before
     * it calls InitAppData.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAppDataFilterDisablesAutoLogoutOverUserValue(): void
    {
        $root = \dirname(__DIR__, 3);
        $dir = \sys_get_temp_dir() . '/x2w-plug-' . \bin2hex(\random_bytes(4));
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

            $aAppData = ['Auth' => true, 'System' => [], 'Plugins' => [], 'AutoLogout' => 30];
            \X2Mail\Engine\Api::Actions()->Plugins()->InitAppData(false, $aAppData);

            self::assertSame(0, $aAppData['AutoLogout']);
            self::assertSame('/oidc/logout', $aAppData['System']['customLogoutLink']);
        } finally {
            self::removeTree($dir);
        }
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
