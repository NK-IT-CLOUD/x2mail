<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Oidc;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Reads the issuer's openid-configuration, insists on the exact issuer and on
 * https endpoints, and caches the document in a file for $ttl seconds.
 */
final class Discovery
{
    public function __construct(
        private ClientInterface $http,
        private RequestFactoryInterface $requests,
        private string $cacheFile,
        private int $ttl = 3600,
    ) {
    }

    public function load(string $issuer): Endpoints
    {
        $raw = $this->rawCached();
        $usable = $raw !== null && ($raw['issuer'] ?? null) === $issuer;

        if ($usable && !$this->isStale()) {
            return $this->endpoints($raw, $issuer);
        }

        try {
            return $this->endpoints($this->fetch($issuer), $issuer);
        } catch (OidcException $e) {
            if ($usable) {
                // IdP unreachable: keep serving the stale-but-matching cache
                // rather than ending every session and blocking every request.
                return $this->endpoints($raw, $issuer);
            }
            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    private function rawCached(): ?array
    {
        if (!\is_file($this->cacheFile)) {
            return null;
        }
        $doc = \json_decode((string) \file_get_contents($this->cacheFile), true);
        return \is_array($doc) ? $doc : null;
    }

    private function isStale(): bool
    {
        return \filemtime($this->cacheFile) + $this->ttl < \time();
    }

    /** @return array<string, mixed> */
    private function fetch(string $issuer): array
    {
        try {
            $response = $this->http->sendRequest(
                $this->requests->createRequest('GET', $issuer . '/.well-known/openid-configuration')
            );
        } catch (\Throwable $e) {
            throw new OidcException('discovery request failed: ' . $e->getMessage(), 0, $e);
        }
        if ($response->getStatusCode() !== 200) {
            throw new OidcException('discovery returned HTTP ' . $response->getStatusCode());
        }
        $doc = \json_decode((string) $response->getBody(), true);
        if (!\is_array($doc)) {
            throw new OidcException('discovery document is not JSON');
        }
        $this->endpoints($doc, $issuer); // validate before caching
        $tmp = $this->cacheFile . '.' . \bin2hex(\random_bytes(4));
        if (@\file_put_contents($tmp, (string) \json_encode($doc)) !== false) {
            @\rename($tmp, $this->cacheFile);
        }
        return $doc;
    }

    /** @param array<string, mixed> $doc */
    private function endpoints(array $doc, string $issuer): Endpoints
    {
        if (($doc['issuer'] ?? null) !== $issuer) {
            throw new OidcException('discovery issuer mismatch');
        }
        return new Endpoints(
            $this->https($doc, 'authorization_endpoint'),
            $this->https($doc, 'token_endpoint'),
            $this->https($doc, 'userinfo_endpoint'),
            $this->https($doc, 'jwks_uri'),
            isset($doc['end_session_endpoint']) ? $this->https($doc, 'end_session_endpoint') : null,
        );
    }

    /** @param array<string, mixed> $doc */
    private function https(array $doc, string $key): string
    {
        $url = $doc[$key] ?? null;
        if (!\is_string($url) || !\str_starts_with($url, 'https://')) {
            throw new OidcException("discovery {$key} missing or not https");
        }
        return $url;
    }
}
