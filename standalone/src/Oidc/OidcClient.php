<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Oidc;

use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessTokenInterface;
use X2Mail\Standalone\Config\Config;

/**
 * Authorization code + PKCE (S256) against the configured issuer. Redirect
 * and logout URIs derive from base_url only — never from request headers.
 */
final class OidcClient
{
    private GenericProvider $provider;

    public function __construct(
        private Config $config,
        private Endpoints $endpoints,
        private TokenValidator $validator,
        ?\GuzzleHttp\ClientInterface $http = null,
    ) {
        $collaborators = $http !== null ? ['httpClient' => $http] : [];
        $this->provider = new GenericProvider([
            'clientId' => $config->oidc->clientId,
            'clientSecret' => $config->oidc->clientSecret,
            'redirectUri' => $config->baseUrl . '/oidc/callback',
            'urlAuthorize' => $endpoints->authorization,
            'urlAccessToken' => $endpoints->token,
            'urlResourceOwnerDetails' => $endpoints->userinfo,
            'pkceMethod' => GenericProvider::PKCE_METHOD_S256,
            'scopes' => $config->oidc->scopes,
            'scopeSeparator' => ' ',
        ], $collaborators);
    }

    /** @return array{url: string, request: LoginRequest} */
    public function beginLogin(): array
    {
        $nonce = \bin2hex(\random_bytes(16));
        $url = $this->provider->getAuthorizationUrl(['nonce' => $nonce]);
        return [
            'url' => $url,
            'request' => new LoginRequest($this->provider->getState(), $nonce, (string) $this->provider->getPkceCode(), \time()),
        ];
    }

    public function completeLogin(string $code, string $state, LoginRequest $pending, int $now): TokenSet
    {
        if (!\hash_equals($pending->state, $state)) {
            throw new LoginFlowException('state mismatch');
        }
        if ($now - $pending->createdAt > LoginRequest::MAX_AGE) {
            throw new LoginFlowException('login request expired');
        }
        $this->provider->setPkceCode($pending->pkceVerifier);
        try {
            $token = $this->request('authorization_code', ['code' => $code]);
        } catch (IdpRequestException $e) {
            throw $e;
        } catch (OidcException $e) {
            // The IdP answered but refused the code (e.g. invalid_grant:
            // already used, expired) — a benign flow failure, not a
            // transport problem and not an access decision.
            throw new LoginFlowException($e->getMessage(), 0, $e);
        }

        $idToken = $token->getValues()['id_token'] ?? null;
        if (!\is_string($idToken) || $idToken === '') {
            throw new OidcException('token response without id_token');
        }
        $this->validator->validateIdToken($idToken, $this->config->oidc->clientId, $pending->nonce);

        return $this->tokenSet($token, $idToken, null);
    }

    public function refresh(TokenSet $current): TokenSet
    {
        if ($current->refreshToken === null) {
            throw new OidcException('no refresh token');
        }
        $token = $this->request('refresh_token', ['refresh_token' => $current->refreshToken]);
        $idToken = $token->getValues()['id_token'] ?? null;
        if (\is_string($idToken) && $idToken !== '') {
            $sub = \is_string($current->claims['sub'] ?? null) ? $current->claims['sub'] : '';
            $this->validator->validateRefreshedIdToken($idToken, $this->config->oidc->clientId, $sub);
        } else {
            $idToken = $current->idToken;
        }
        return $this->tokenSet($token, $idToken, $current->refreshToken);
    }

    public function logoutUrl(TokenSet $tokens): ?string
    {
        return $this->logoutUrlForHint($tokens->idToken);
    }

    public function logoutUrlForHint(?string $idTokenHint): ?string
    {
        if ($this->endpoints->endSession === null) {
            return null;
        }
        $params = [
            'post_logout_redirect_uri' => $this->config->baseUrl . '/',
            'client_id' => $this->config->oidc->clientId,
        ];
        if ($idTokenHint !== null) {
            $params['id_token_hint'] = $idTokenHint;
        }
        return $this->endpoints->endSession . '?' . \http_build_query($params);
    }

    /** @param array<string, string> $options */
    private function request(string $grant, array $options): AccessTokenInterface
    {
        try {
            return $this->provider->getAccessToken($grant, $options);
        } catch (IdentityProviderException $e) {
            // The IdP answered with a well-formed OAuth error (e.g.
            // invalid_grant) — definitive, not a transport failure.
            throw new OidcException("{$grant} grant failed: " . $e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            throw new IdpRequestException("{$grant} grant failed: " . $e->getMessage(), 0, $e);
        }
    }

    private function tokenSet(AccessTokenInterface $token, string $idToken, ?string $previousRefresh): TokenSet
    {
        $claims = $this->validator->validateAccessToken($token->getToken());
        return new TokenSet(
            $token->getToken(),
            $token->getRefreshToken() ?? $previousRefresh,
            $idToken,
            $token->getExpires() ?? (int) $claims['exp'],
            $claims,
        );
    }
}
