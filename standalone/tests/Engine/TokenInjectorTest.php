<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Tests\Engine;

use PHPUnit\Framework\TestCase;
use X2Mail\Engine\Model\AdditionalAccount;
use X2Mail\Engine\Model\MainAccount;
use X2Mail\Mail\Net\ConnectSettings;
use X2Mail\Standalone\Auth\Identity;
use X2Mail\Standalone\Engine\TokenInjector;

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
class TokenInjectorTest extends TestCase
{
    private static ?string $tempPrivateData = null;

    public static function setUpBeforeClass(): void
    {
        $base = \dirname(__DIR__, 3) . '/app/x2mail/v/current/app/libraries/X2Mail/Engine';
        require_once $base . '/sensitivestring.php';

        // Deviation from the brief (see task-6-report.md): ConnectSettings's
        // constructor builds an SSLContext, which reads
        // X2Mail\Engine\Api::Config() — the engine's own config loader needs
        // APP_CONFIGURATION_NAME/APP_PRIVATE_DATA plus a readable, non-empty
        // application.ini (else a fresh install tries to Save() one, which
        // needs APP_VERSION/APP_SALT — full engine bootstrap). It also loads
        // X2Mail\Engine\Crypt, whose file is lowercase and unreachable
        // through Composer's PSR-4 mapping. Both are provided here, without
        // running the real engine bootstrap (that stays exclusive to
        // ProvisionerTest, which needs its own isolated data dir).
        \spl_autoload_register(function (string $sClassName) use ($base): void {
            if (\str_starts_with($sClassName, 'X2Mail\\Engine\\')) {
                $libPath = \dirname($base, 2) . '/';
                $file = $libPath . 'X2Mail/Engine/' . \strtolower(\strtr(\substr($sClassName, 14), '\\', \DIRECTORY_SEPARATOR)) . '.php';
                if (\is_file($file)) {
                    require_once $file;
                }
            }
        });

        if (!\defined('APP_CONFIGURATION_NAME')) {
            \define('APP_CONFIGURATION_NAME', '');
        }
        if (!\defined('APP_PRIVATE_DATA')) {
            self::$tempPrivateData = \sys_get_temp_dir() . '/x2w-tokeninjector-' . \bin2hex(\random_bytes(4)) . '/';
            \mkdir(self::$tempPrivateData . 'configs', 0700, true);
            \file_put_contents(self::$tempPrivateData . 'configs/application.ini', "[security]\nencrypt_cipher = \"aes-256-cbc-hmac-sha1\"\n");
            \define('APP_PRIVATE_DATA', self::$tempPrivateData);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$tempPrivateData !== null) {
            \unlink(self::$tempPrivateData . 'configs/application.ini');
            \rmdir(self::$tempPrivateData . 'configs');
            \rmdir(self::$tempPrivateData);
            self::$tempPrivateData = null;
        }
    }

    private static bool $handlersRestored = false;

    protected function tearDown(): void
    {
        // The engine's Actions singleton (first touched by SensitiveString's
        // logMask(), called from ConnectSettings::__set() above) builds its
        // main Logger, which registers global error/exception handlers on
        // first use (Api::Actions() is a static cache, so this fires exactly
        // once). Restore right away so PHPUnit doesn't flag the test as
        // risky and later test classes in this run see a clean stack.
        if (!self::$handlersRestored) {
            \restore_error_handler();
            \restore_exception_handler();
            self::$handlersRestored = true;
        }
    }

    private function settings(string $passphrase): ConnectSettings
    {
        $s = new ConnectSettings();
        $s->passphrase = $passphrase;
        $s->SASLMechanisms = ['XOAUTH2', 'PLAIN'];
        return $s;
    }

    private function identity(): Identity
    {
        return new Identity('anna@kunde-a.at', 'anna', 'kunde-a', 'ACCESS-TOKEN', []);
    }

    public function testMainAccountSentinelGetsTokenAndOauthbearerFirst(): void
    {
        $s = $this->settings('oidc_login|anna');
        (new TokenInjector())->apply(new MainAccount(), $s, $this->identity());
        self::assertSame('ACCESS-TOKEN', $s->passphrase);
        self::assertSame(['OAUTHBEARER', 'XOAUTH2', 'PLAIN'], $s->SASLMechanisms);
    }

    public function testNoIdentityLeavesSettingsUntouched(): void
    {
        $s = $this->settings('oidc_login|anna');
        (new TokenInjector())->apply(new MainAccount(), $s, null);
        self::assertSame('oidc_login|anna', $s->passphrase);
    }

    public function testNonSentinelPasswordUntouched(): void
    {
        $s = $this->settings('real-password');
        (new TokenInjector())->apply(new MainAccount(), $s, $this->identity());
        self::assertSame('real-password', $s->passphrase);
    }

    public function testAdditionalAccountUntouched(): void
    {
        $s = $this->settings('oidc_login|anna');
        (new TokenInjector())->apply(new AdditionalAccount(), $s, $this->identity());
        self::assertSame('oidc_login|anna', $s->passphrase);
    }
}
