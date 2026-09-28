<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Dav;

/** Per-user configuration for the CardDAV address book driver. */
final class DavContext
{
    public function __construct(
        public readonly string $davRoot,
        public readonly int $timeoutS,
        public readonly bool $writablePersonal,
        public readonly bool $includeSystem,
        /** sha256 of the uid, used as the cache key suffix. */
        public readonly string $userKey,
        public readonly string $cacheDir,
    ) {
    }
}
