<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Tests\Config;

use PHPUnit\Framework\TestCase;
use X2Mail\Standalone\Config\Config;
use X2Mail\Standalone\Config\ConfigException;

class ConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/x2w-config-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0700, true);
        \file_put_contents($this->dir . '/secret', "s3cret\n");
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->dir . '/*') ?: [] as $f) {
            \unlink($f);
        }
        \rmdir($this->dir);
    }

    /** @return array<string, mixed> */
    private function valid(): array
    {
        return [
            'webmail' => ['base_url' => 'https://webmail.example.org/'],
            'oidc' => [
                'issuer' => 'https://id.example.org/realms/central',
                'client_id' => 'webmail',
                'client_secret_file' => $this->dir . '/secret',
            ],
            'mail' => ['host' => 'mail.example.org'],
            'rules' => [
                'mail_user' => ['claim' => 'realm_access.roles', 'any_of' => ['mailxyz']],
                'nc_user' => ['claim' => 'resource_access.webmail.roles', 'all_of' => ['abz']],
            ],
            'access' => ['login_requires' => 'mail_user'],
            'dav' => [
                'contacts' => [
                    'type' => 'carddav',
                    'requires' => 'nc_user',
                    'url_template' => 'https://{tenant}.example.cloud/remote.php/dav/',
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    private function expectError(array $data, string $messageStart): void
    {
        try {
            Config::fromArray($data);
            self::fail('expected ConfigException');
        } catch (ConfigException $e) {
            self::assertStringStartsWith($messageStart, $e->getMessage());
        }
    }

    public function testValidConfigWithDefaults(): void
    {
        $c = Config::fromArray($this->valid());

        self::assertSame('https://webmail.example.org', $c->baseUrl);
        self::assertSame('/var/lib/x2mail-webmail', $c->dataDir);
        self::assertSame('https://id.example.org/realms/central', $c->oidc->issuer);
        self::assertSame('s3cret', $c->oidc->clientSecret);
        self::assertSame('organization', $c->oidc->tenantClaim);
        self::assertSame(['openid', 'email', 'profile', 'organization'], $c->oidc->scopes);
        self::assertSame(1, \preg_match($c->oidc->tenantRegex, 'nkit'));
        self::assertSame([993, 587, 4190], [$c->mail->imapPort, $c->mail->smtpPort, $c->mail->sievePort]);
        self::assertSame(['mailxyz'], $c->rules['mail_user']->anyOf);
        self::assertSame([], $c->rules['mail_user']->allOf);
        self::assertTrue($c->rules['mail_user']->stripPath);
        self::assertSame('mail_user', $c->loginRequires);
        $dav = $c->dav['contacts'];
        self::assertSame(['carddav', true, 3, true, 'personal'], [$dav->type, $dav->enabled, $dav->timeoutS, $dav->includeSystemAddressbook, $dav->writable]);
    }

    public function testFromFileParsesToml(): void
    {
        $toml = <<<TOML
        [webmail]
        base_url = "https://webmail.example.org"
        [oidc]
        issuer = "https://id.example.org/realms/central"
        client_id = "webmail"
        client_secret_file = "{$this->dir}/secret"
        [mail]
        host = "mail.example.org"
        [rules.mail_user]
        claim = "groups"
        any_of = ["mailxyz"]
        [access]
        login_requires = "mail_user"
        TOML;
        \file_put_contents($this->dir . '/webmail.toml', $toml);

        self::assertSame('groups', Config::fromFile($this->dir . '/webmail.toml')->rules['mail_user']->claim);
    }

    public function testInvalidTomlIsConfigError(): void
    {
        \file_put_contents($this->dir . '/webmail.toml', "[webmail\nbase_url = ");
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('invalid TOML');
        Config::fromFile($this->dir . '/webmail.toml');
    }

    public function testMissingFileIsConfigError(): void
    {
        $this->expectException(ConfigException::class);
        Config::fromFile($this->dir . '/nope.toml');
    }

    public function testUnknownRuleReference(): void
    {
        $d = $this->valid();
        $d['access']['login_requires'] = 'mail_usr';
        $this->expectError($d, 'access.login_requires');
    }

    public function testUnknownDavRuleReference(): void
    {
        $d = $this->valid();
        $d['dav']['contacts']['requires'] = 'nope';
        $this->expectError($d, 'dav.contacts.requires');
    }

    public function testUnknownDavType(): void
    {
        $d = $this->valid();
        $d['dav']['contacts']['type'] = 'caldav';
        $this->expectError($d, 'dav.contacts.type');
    }

    public function testHttpUrlTemplateRejected(): void
    {
        $d = $this->valid();
        $d['dav']['contacts']['url_template'] = 'http://{tenant}.example.cloud/';
        $this->expectError($d, 'dav.contacts.url_template');
    }

    public function testHttpBaseUrlRejected(): void
    {
        $d = $this->valid();
        $d['webmail']['base_url'] = 'http://webmail.example.org';
        $this->expectError($d, 'webmail.base_url');
    }

    public function testBaseUrlWithPathRejected(): void
    {
        $d = $this->valid();
        $d['webmail']['base_url'] = 'https://webmail.example.org/sub';
        $this->expectError($d, 'webmail.base_url: must be an origin without path');
    }

    public function testBaseUrlWithQueryRejected(): void
    {
        $d = $this->valid();
        $d['webmail']['base_url'] = 'https://webmail.example.org/?x=1';
        $this->expectError($d, 'webmail.base_url: must be an origin without path');
    }

    public function testBaseUrlWithFragmentRejected(): void
    {
        $d = $this->valid();
        $d['webmail']['base_url'] = 'https://webmail.example.org/#frag';
        $this->expectError($d, 'webmail.base_url: must be an origin without path');
    }

    public function testBaseUrlBareOriginAccepted(): void
    {
        $d = $this->valid();
        $d['webmail']['base_url'] = 'https://webmail.example.org';
        self::assertSame('https://webmail.example.org', Config::fromArray($d)->baseUrl);
    }

    public function testTenantPatternIsAnchored(): void
    {
        $d = $this->valid();
        $d['oidc']['tenant_pattern'] = '[a-z]+';
        $c = Config::fromArray($d);
        self::assertSame(0, \preg_match($c->oidc->tenantRegex, 'evil.com/x'));
        self::assertSame(1, \preg_match($c->oidc->tenantRegex, 'nkit'));
    }

    public function testInvalidTenantPattern(): void
    {
        $d = $this->valid();
        $d['oidc']['tenant_pattern'] = '^[a-z';
        $this->expectError($d, 'oidc.tenant_pattern');
    }

    public function testMissingSecretFile(): void
    {
        $d = $this->valid();
        $d['oidc']['client_secret_file'] = $this->dir . '/missing';
        $this->expectError($d, 'oidc.client_secret_file');
    }

    public function testEmptySecretFile(): void
    {
        \file_put_contents($this->dir . '/secret', "  \n");
        $this->expectError($this->valid(), 'oidc.client_secret_file');
    }

    public function testRuleNeedsAnyOfOrAllOf(): void
    {
        $d = $this->valid();
        $d['rules']['mail_user'] = ['claim' => 'groups'];
        $this->expectError($d, 'rules.mail_user');
    }

    public function testUnknownKeyIsRejected(): void
    {
        $d = $this->valid();
        $d['rules']['mail_user']['any_off'] = ['x'];
        $this->expectError($d, 'rules.mail_user.any_off');
    }

    public function testRedisSessionBackendNotSupportedYet(): void
    {
        $d = $this->valid();
        $d['session'] = ['backend' => 'redis'];
        $this->expectError($d, 'session.backend');
    }

    public function testThemeSectionMissingMeansNoPrimaryColor(): void
    {
        self::assertNull(Config::fromArray($this->valid())->theme->primaryColor);
    }

    public function testThemePrimaryColor(): void
    {
        $d = $this->valid();
        $d['theme'] = ['primary_color' => '#aa3300'];
        self::assertSame('#aa3300', Config::fromArray($d)->theme->primaryColor);
    }

    public function testThemePrimaryColorMustBeSixDigitHex(): void
    {
        foreach (['red', '#abc', '#aa3300; x', "#aa3300\n", '#gg3300', ''] as $bad) {
            $d = $this->valid();
            $d['theme'] = ['primary_color' => $bad];
            $this->expectError($d, 'theme.primary_color');
        }
    }

    public function testThemeUnknownKeyIsRejected(): void
    {
        $d = $this->valid();
        $d['theme'] = ['color' => '#aa3300'];
        $this->expectError($d, 'theme.color');
    }

    public function testWrongValueType(): void
    {
        $d = $this->valid();
        $d['rules']['mail_user']['any_of'] = 'mailxyz';
        $this->expectError($d, 'rules.mail_user.any_of');
    }

    public function testInvalidWritable(): void
    {
        $d = $this->valid();
        $d['dav']['contacts']['writable'] = 'all';
        $this->expectError($d, 'dav.contacts.writable');
    }

    public function testPortOutOfRange(): void
    {
        $d = $this->valid();
        $d['mail']['imap_port'] = 70000;
        $this->expectError($d, 'mail.imap_port');
    }
}
