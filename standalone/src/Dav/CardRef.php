<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Dav;

final class CardRef
{
    public function __construct(
        public readonly string $href,
        public readonly string $etag,
        public readonly string $vcard,
    ) {
    }
}
