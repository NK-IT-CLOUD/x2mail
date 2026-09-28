<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Auth;

/** What finishLogin() decided, and which page the caller must show for it. */
enum AuthOutcome
{
    /** Login succeeded, a session was established. */
    case Ok;
    /** The IdP answered, but the account has no access (403). */
    case Denied;
    /** A benign login-flow failure: reused/expired/replayed request (400). */
    case Expired;
    /** The IdP could not be reached (503, worth a retry). */
    case Unavailable;
}
