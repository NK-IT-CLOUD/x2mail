<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Oidc;

/** The IdP's token endpoint could not be reached or answered with an error — worth a retry. */
final class IdpRequestException extends OidcException
{
}
