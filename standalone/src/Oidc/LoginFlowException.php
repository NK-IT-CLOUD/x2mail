<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Oidc;

/**
 * A benign login-flow failure: the pending request was reused, replayed, or
 * has expired (second tab, back button, stale bookmark), or the IdP rejected
 * the code exchange as no-longer-valid (e.g. invalid_grant). Not an access
 * decision — the visitor just needs to start over.
 */
final class LoginFlowException extends OidcException
{
}
