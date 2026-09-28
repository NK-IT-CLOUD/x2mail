<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Dav;

final class AddressBookRef
{
    public function __construct(
        public readonly string $href,
        public readonly string $name,
        public readonly string $displayName,
        public readonly bool $writable,
        public readonly bool $system,
        public readonly bool $appGenerated,
    ) {
    }
}
