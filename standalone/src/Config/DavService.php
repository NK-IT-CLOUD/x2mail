<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Config;

/** One [dav.<name>] entry. Only carddav adapters exist in this version. */
final class DavService
{
    public const TYPES = ['carddav'];

    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $enabled,
        public readonly string $requires,
        public readonly string $urlTemplate,
        public readonly int $timeoutS,
        public readonly bool $includeSystemAddressbook,
        public readonly string $writable,
    ) {
    }
}
