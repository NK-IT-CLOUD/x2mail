<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Oidc;

/** Any OIDC failure. Messages never contain token material. */
class OidcException extends \RuntimeException
{
}
