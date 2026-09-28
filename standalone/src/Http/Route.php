<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Http;

enum Route
{
    case Login;
    case Callback;
    case Logout;
    case Health;
    case Blocked;
    case Engine;
}
