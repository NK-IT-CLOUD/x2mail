<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Tests\Engine;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use X2Mail\Engine\Host;
use X2Mail\Standalone\Auth\Identity;
use X2Mail\Standalone\Config\Config;
use X2Mail\Standalone\Engine\EngineRunner;
use X2Mail\Standalone\Engine\Provisioner;
use X2Mail\Standalone\StandaloneBridge;

/**
 * The mail-account check the front controller runs before the engine page.
 * Separate process: the engine defines global constants.
 */
class EngineRunnerTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFindsMailAccountThroughInstanceDomain(): void
    {
        $dir = $this->provision();
        try {
            Host::set(new StandaloneBridge(new Identity('anna@kunde-a.at', 'anna', null, 'AT', []), 'sess-1'));
            self::assertTrue((new EngineRunner($dir . '/data', \dirname(__DIR__, 3) . '/app/index.php'))->hasMailAccount());
        } finally {
            self::removeTree($dir);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNoMailAccountWithoutDomainConfig(): void
    {
        $dir = $this->provision();
        try {
            \unlink($dir . '/data/engine/_data_/_default_/domains/mail.example.org.json');
            // The engine logs the missing config to stderr, which PHPUnit treats as a failure here.
            \X2Mail\Engine\Api::Logger()->SetLevel(\LOG_EMERG);
            Host::set(new StandaloneBridge(new Identity('anna@kunde-a.at', 'anna', null, 'AT', []), 'sess-1'));
            self::assertFalse((new EngineRunner($dir . '/data', \dirname(__DIR__, 3) . '/app/index.php'))->hasMailAccount());
        } finally {
            self::removeTree($dir);
        }
    }

    private function provision(): string
    {
        $root = \dirname(__DIR__, 3);
        $dir = \sys_get_temp_dir() . '/x2w-run-' . \bin2hex(\random_bytes(4));
        \mkdir($dir . '/data', 0700, true);
        \file_put_contents($dir . '/secret', 's');
        $config = Config::fromArray([
            'webmail' => ['base_url' => 'https://webmail.example.org', 'data_dir' => $dir . '/data'],
            'oidc' => ['issuer' => 'https://id.example.org/realms/central', 'client_id' => 'webmail', 'client_secret_file' => $dir . '/secret'],
            'mail' => ['host' => 'mail.example.org'],
            'rules' => ['mail_user' => ['claim' => 'groups', 'any_of' => ['mail']]],
            'access' => ['login_requires' => 'mail_user'],
        ]);
        (new Provisioner($config, $root . '/app/index.php', $root . '/standalone/engine-plugin/standalone'))->run();
        return $dir;
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
