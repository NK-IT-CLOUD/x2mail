<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Tests\Support;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/** One throwaway ES256 key pair per test run, exposed as JWKS + signer. */
final class TestKeys
{
    private static ?string $pem = null;

    /** @var array<string, mixed>|null */
    private static ?array $jwks = null;

    private static function init(): void
    {
        if (self::$pem !== null) {
            return;
        }
        $key = \openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        \openssl_pkey_export($key, $pem);
        $d = \openssl_pkey_get_details($key);
        $b64 = static fn (string $s): string => \rtrim(\strtr(\base64_encode($s), '+/', '-_'), '=');
        self::$pem = $pem;
        self::$jwks = ['keys' => [[
            'kty' => 'EC', 'crv' => 'P-256', 'kid' => 'k1', 'alg' => 'ES256', 'use' => 'sig',
            'x' => $b64($d['ec']['x']), 'y' => $b64($d['ec']['y']),
        ]]];
    }

    /** @param array<string, mixed> $claims */
    public static function issue(array $claims, ?string $kid = 'k1'): string
    {
        self::init();
        return JWT::encode($claims, (string) self::$pem, 'ES256', $kid);
    }

    /** @return array<string, mixed> */
    public static function jwks(): array
    {
        self::init();
        return (array) self::$jwks;
    }

    /** @return array<string, Key> */
    public static function keySet(): array
    {
        return JWK::parseKeySet(self::jwks());
    }
}
