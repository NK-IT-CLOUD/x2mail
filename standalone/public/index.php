<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use X2Mail\Standalone\Http\App;
use X2Mail\Standalone\Http\ErrorResponses;
use X2Mail\Standalone\Log;

$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
if (\parse_url($uri, \PHP_URL_PATH) === '/healthz') {
    // Own process only — must not depend on the IdP or the config being loadable.
    \header('Content-Type: text/plain');
    echo 'ok';
    return;
}

try {
    $response = App::fromEnvironment()->handle($uri, $_GET, $_SERVER);
} catch (\Throwable $e) {
    // Catch-all: an uncaught exception must never leak a stack trace to the client.
    $response = ErrorResponses::for($e, new Log());
}

if ($response !== null) {
    if (!\headers_sent()) {
        \http_response_code($response['status']);
        foreach ($response['headers'] as $name => $value) {
            \header("{$name}: {$value}");
        }
    }
    echo $response['body'];
}
