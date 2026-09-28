<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Oidc;

final class Endpoints
{
    public function __construct(
        public readonly string $authorization,
        public readonly string $token,
        public readonly string $userinfo,
        public readonly string $jwksUri,
        public readonly ?string $endSession,
    ) {
    }
}
