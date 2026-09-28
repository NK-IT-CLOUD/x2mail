<?php

declare(strict_types=1);

namespace X2Mail\Standalone;

use X2Mail\Standalone\Auth\Identity;
use X2Mail\Standalone\Dav\DavContext;

/** Identity, DAV context and theme colour of the current request, read by the engine plugin. */
final class Runtime
{
    private static ?Identity $identity = null;

    private static ?DavContext $dav = null;

    private static ?string $primaryColor = null;

    public static function set(?Identity $identity): void
    {
        self::$identity = $identity;
    }

    public static function identity(): ?Identity
    {
        return self::$identity;
    }

    public static function setDav(?DavContext $dav): void
    {
        self::$dav = $dav;
    }

    public static function dav(): ?DavContext
    {
        return self::$dav;
    }

    public static function setPrimaryColor(?string $color): void
    {
        self::$primaryColor = $color;
    }

    public static function primaryColor(): ?string
    {
        return self::$primaryColor;
    }

    public static function reset(): void
    {
        self::$identity = null;
        self::$dav = null;
        self::$primaryColor = null;
    }
}
