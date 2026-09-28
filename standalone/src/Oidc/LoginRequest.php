<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Oidc;

/** The pending authorization request, kept in the session until the callback. */
final class LoginRequest
{
    public const MAX_AGE = 600;

    public function __construct(
        public readonly string $state,
        public readonly string $nonce,
        public readonly string $pkceVerifier,
        public readonly int $createdAt,
    ) {
    }

    /** @return array<string, string|int> */
    public function toArray(): array
    {
        return ['state' => $this->state, 'nonce' => $this->nonce, 'pkceVerifier' => $this->pkceVerifier, 'createdAt' => $this->createdAt];
    }

    /** @param array<mixed> $a */
    public static function fromArray(array $a): ?self
    {
        foreach (['state', 'nonce', 'pkceVerifier'] as $k) {
            if (!\is_string($a[$k] ?? null) || $a[$k] === '') {
                return null;
            }
        }
        if (!\is_int($a['createdAt'] ?? null)) {
            return null;
        }
        return new self($a['state'], $a['nonce'], $a['pkceVerifier'], $a['createdAt']);
    }
}
