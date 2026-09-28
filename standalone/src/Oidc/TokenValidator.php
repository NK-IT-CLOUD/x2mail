<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Oidc;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Signature via php-jwt: the algorithm comes from the JWKS key, not the token
 * header, so an HS256 token against an EC/RSA key is rejected. On top: issuer,
 * mandatory exp, and for ID tokens audience/azp/nonce (OIDC Core 3.1.3.7).
 */
final class TokenValidator
{
    /** @param array<string, Key>|\ArrayAccess<string, Key> $keys */
    public function __construct(
        private array|\ArrayAccess $keys,
        private string $issuer,
        private int $leeway = 30,
    ) {
    }

    /** @return array<string, mixed> */
    public function validateIdToken(string $jwt, string $clientId, string $nonce): array
    {
        $claims = $this->decode($jwt);
        $aud = \is_string($claims['aud'] ?? null) ? [$claims['aud']] : ($claims['aud'] ?? []);
        if (!\is_array($aud) || !\in_array($clientId, $aud, true)) {
            throw new OidcException('id token aud does not contain the client');
        }
        if (\count($aud) > 1 && ($claims['azp'] ?? null) !== $clientId) {
            throw new OidcException('id token azp is not the client');
        }
        if (!\is_string($claims['nonce'] ?? null) || !\hash_equals($nonce, $claims['nonce'])) {
            throw new OidcException('id token nonce mismatch');
        }
        return $claims;
    }

    /** @return array<string, mixed> */
    public function validateAccessToken(string $jwt): array
    {
        return $this->decode($jwt);
    }

    /**
     * A refreshed ID token must belong to the same subject and client (OIDC Core 12.2).
     *
     * @return array<string, mixed>
     */
    public function validateRefreshedIdToken(string $jwt, string $clientId, string $expectedSub): array
    {
        $claims = $this->decode($jwt);
        $aud = \is_string($claims['aud'] ?? null) ? [$claims['aud']] : ($claims['aud'] ?? []);
        if (!\is_array($aud) || !\in_array($clientId, $aud, true)) {
            throw new OidcException('refreshed id token aud does not contain the client');
        }
        if (\count($aud) > 1 && ($claims['azp'] ?? null) !== $clientId) {
            throw new OidcException('refreshed id token azp is not the client');
        }
        if (($claims['sub'] ?? null) !== $expectedSub) {
            throw new OidcException('refreshed id token is for another subject');
        }
        return $claims;
    }

    /** @return array<string, mixed> */
    private function decode(string $jwt): array
    {
        $prevLeeway = JWT::$leeway;
        JWT::$leeway = $this->leeway;
        try {
            $object = JWT::decode($jwt, $this->keys);
        } catch (\Throwable $e) {
            throw new OidcException('token rejected: ' . $e->getMessage(), 0, $e);
        } finally {
            JWT::$leeway = $prevLeeway;
        }
        /** @var array<string, mixed> $claims */
        $claims = \json_decode((string) \json_encode($object), true);
        if (($claims['iss'] ?? null) !== $this->issuer) {
            throw new OidcException('token issuer mismatch');
        }
        if (!\is_int($claims['exp'] ?? null)) {
            throw new OidcException('token without exp');
        }
        return $claims;
    }
}
