<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Oidc;

/** Tokens of one login. Lives only in the server-side session. */
final class TokenSet
{
    /** @param array<string, mixed> $claims validated access token claims */
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly string $idToken,
        public readonly int $expiresAt,
        public readonly array $claims,
    ) {
    }

    public function expiresWithin(int $seconds, int $now): bool
    {
        return $this->expiresAt - $now < $seconds;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'accessToken' => $this->accessToken,
            'refreshToken' => $this->refreshToken,
            'idToken' => $this->idToken,
            'expiresAt' => $this->expiresAt,
            'claims' => $this->claims,
        ];
    }

    /** @param array<mixed> $a */
    public static function fromArray(array $a): ?self
    {
        if (!\is_string($a['accessToken'] ?? null) || !\is_string($a['idToken'] ?? null)
            || !\is_int($a['expiresAt'] ?? null) || !\is_array($a['claims'] ?? null)
            || !(\is_string($a['refreshToken'] ?? null) || ($a['refreshToken'] ?? null) === null)
        ) {
            return null;
        }
        /** @var array<string, mixed> $claims */
        $claims = $a['claims'];
        return new self($a['accessToken'], $a['refreshToken'], $a['idToken'], $a['expiresAt'], $claims);
    }
}
