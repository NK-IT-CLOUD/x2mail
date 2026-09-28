<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Config;

/** Optional look: primary colour as #rrggbb, null keeps the default. */
final class ThemeSettings
{
    public const COLOR_PATTERN = '/\A#[0-9a-fA-F]{6}\z/';

    public function __construct(
        public readonly ?string $primaryColor = null,
    ) {
    }
}
