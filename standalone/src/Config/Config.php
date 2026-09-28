<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Config;

use Internal\Toml\Toml;

/**
 * webmail.toml, validated at start. Anything wrong is a ConfigException naming
 * the key — the container must not start half-configured.
 */
final class Config
{
    public const DEFAULT_TENANT_PATTERN = '^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$';

    /**
     * @param array<string, Rule> $rules
     * @param array<string, DavService> $dav
     */
    public function __construct(
        public readonly string $baseUrl,
        public readonly string $dataDir,
        public readonly OidcSettings $oidc,
        public readonly MailSettings $mail,
        public readonly array $rules,
        public readonly string $loginRequires,
        public readonly array $dav,
        public readonly ThemeSettings $theme = new ThemeSettings(),
    ) {
    }

    public static function fromFile(string $path): self
    {
        $toml = \is_readable($path) ? \file_get_contents($path) : false;
        if ($toml === false) {
            throw new ConfigException("{$path}: not readable");
        }
        try {
            $data = Toml::parseToArray($toml);
        } catch (\Throwable $e) {
            throw new ConfigException("{$path}: invalid TOML: " . $e->getMessage(), 0, $e);
        }
        /** @var array<string, mixed> $data */
        return self::fromArray($data);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $root = new Section($data, '');

        $webmail = $root->section('webmail');
        $rawBaseUrl = $webmail->httpsUrl('base_url');
        $baseUrlParts = \parse_url($rawBaseUrl);
        $baseUrlPath = \is_array($baseUrlParts) ? ($baseUrlParts['path'] ?? '') : '';
        if (!\is_array($baseUrlParts) || !\in_array($baseUrlPath, ['', '/'], true)
            || isset($baseUrlParts['query']) || isset($baseUrlParts['fragment'])
        ) {
            throw new ConfigException($webmail->key('base_url') . ': must be an origin without path');
        }
        $baseUrl = \rtrim($rawBaseUrl, '/');
        $dataDir = \rtrim($webmail->string('data_dir', '/var/lib/x2mail-webmail'), '/');

        $o = $root->section('oidc');
        $oidc = new OidcSettings(
            issuer: \rtrim($o->httpsUrl('issuer'), '/'),
            clientId: $o->string('client_id'),
            clientSecret: self::readSecret($o->string('client_secret_file'), $o->key('client_secret_file')),
            tenantClaim: $o->string('tenant_claim', 'organization'),
            tenantRegex: self::regex($o->string('tenant_pattern', self::DEFAULT_TENANT_PATTERN), $o->key('tenant_pattern')),
            scopes: $o->stringList('scopes', ['openid', 'email', 'profile', 'organization']),
        );

        $m = $root->section('mail');
        $mail = new MailSettings(
            $m->string('host'),
            $m->int('imap_port', 993, 1, 65535),
            $m->int('smtp_port', 587, 1, 65535),
            $m->int('sieve_port', 4190, 1, 65535),
        );

        $session = $root->optionalSection('session');
        $backend = $session?->string('backend', 'files') ?? 'files';
        if ($backend !== 'files') {
            throw new ConfigException("session.backend: '{$backend}' is not supported yet (only 'files')");
        }

        $t = $root->optionalSection('theme');
        $primaryColor = $t?->optionalString('primary_color');
        if ($t !== null && $primaryColor !== null) {
            if (\preg_match(ThemeSettings::COLOR_PATTERN, $primaryColor) !== 1) {
                throw new ConfigException($t->key('primary_color') . ': must be a colour like #00679e');
            }
        }
        $theme = new ThemeSettings($primaryColor);

        $rules = [];
        foreach ($root->sections('rules') as $name => $r) {
            $rule = new Rule($name, $r->string('claim'), $r->stringList('any_of', []), $r->stringList('all_of', []), $r->bool('strip_path', true));
            $rules[$name] = $rule;
        }

        $access = $root->section('access');
        $loginRequires = self::ruleRef($rules, $access->string('login_requires'), $access->key('login_requires'));

        $dav = [];
        foreach ($root->sections('dav', true) as $name => $d) {
            $type = $d->string('type');
            if (!\in_array($type, DavService::TYPES, true)) {
                throw new ConfigException($d->key('type') . ": unknown type '{$type}'");
            }
            $writable = $d->string('writable', 'personal');
            if (!\in_array($writable, ['personal', 'none'], true)) {
                throw new ConfigException($d->key('writable') . ": must be 'personal' or 'none'");
            }
            $dav[$name] = new DavService(
                $name,
                $type,
                $d->bool('enabled', true),
                self::ruleRef($rules, $d->string('requires'), $d->key('requires')),
                $d->httpsUrl('url_template'),
                $d->int('timeout_s', 3, 1, 60),
                $d->bool('include_system_addressbook', true),
                $writable,
            );
        }

        $root->assertComplete();

        return new self($baseUrl, $dataDir, $oidc, $mail, $rules, $loginRequires, $dav, $theme);
    }

    private static function readSecret(string $file, string $key): string
    {
        $secret = \is_readable($file) ? \trim((string) \file_get_contents($file)) : '';
        if ($secret === '') {
            throw new ConfigException("{$key}: file missing, unreadable or empty");
        }
        return $secret;
    }

    private static function regex(string $pattern, string $key): string
    {
        $regex = '~\A(?:' . \str_replace('~', '\~', $pattern) . ')\z~';
        if (@\preg_match($regex, '') === false) {
            throw new ConfigException("{$key}: invalid regular expression");
        }
        return $regex;
    }

    /** @param array<string, Rule> $rules */
    private static function ruleRef(array $rules, string $name, string $key): string
    {
        if (!isset($rules[$name])) {
            throw new ConfigException("{$key}: unknown rule '{$name}'");
        }
        return $name;
    }
}
