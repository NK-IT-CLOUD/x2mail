<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Dav;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use X2Mail\Standalone\Log;
use X2Mail\Standalone\Runtime;

/** Builds the engine's address book driver from the current request's Runtime state. */
final class AddressBookFactory
{
    public static function fromRuntime(Log $log): ?CardDavAddressBook
    {
        $identity = Runtime::identity();
        $ctx = Runtime::dav();
        if ($identity === null || $ctx === null) {
            return null;
        }

        $http = new Client(['timeout' => $ctx->timeoutS, 'connect_timeout' => \min(3, $ctx->timeoutS)]);
        $factory = new HttpFactory();
        $cache = new FilesystemAdapter('dav', 300, $ctx->cacheDir);
        $client = new CardDavClient($http, $factory, $factory, $ctx->davRoot, $identity->accessToken, $ctx->timeoutS);

        return new CardDavAddressBook($client, $ctx, $cache, $log);
    }
}
