<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Config;

/** Invalid webmail.toml. The message starts with the offending key path. */
final class ConfigException extends \RuntimeException
{
}
