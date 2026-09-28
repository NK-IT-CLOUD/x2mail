<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Dav;

/** Any CardDAV failure. Messages never contain the access token. */
class DavException extends \RuntimeException
{
    private bool $authFailedFlag = false;

    public static function requestFailed(): self
    {
        return new self('dav request failed');
    }

    public static function authFailure(): self
    {
        $e = new self('dav authentication failed');
        $e->authFailedFlag = true;
        return $e;
    }

    public static function preconditionFailed(): self
    {
        return new self('precondition failed');
    }

    public static function httpStatus(int $status): self
    {
        return new self('dav request failed with http ' . $status);
    }

    public static function invalidResponse(string $reason): self
    {
        return new self('dav response invalid: ' . $reason);
    }

    public function authFailed(): bool
    {
        return $this->authFailedFlag;
    }
}
