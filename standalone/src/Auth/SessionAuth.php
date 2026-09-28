<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Auth;

use X2Mail\Standalone\Access\Claims;
use X2Mail\Standalone\Access\RuleEvaluator;
use X2Mail\Standalone\Access\TenantResolver;
use X2Mail\Standalone\Config\Config;
use X2Mail\Standalone\Log;
use X2Mail\Standalone\Oidc\IdpRequestException;
use X2Mail\Standalone\Oidc\LoginFlowException;
use X2Mail\Standalone\Oidc\LoginRequest;
use X2Mail\Standalone\Oidc\OidcClient;
use X2Mail\Standalone\Oidc\OidcException;
use X2Mail\Standalone\Oidc\TokenSet;
use X2Mail\Standalone\Session\Session;

/**
 * Login state of one browser session: pending authorization request, tokens,
 * refresh before expiry, and the access decision — re-evaluated after every
 * refresh, so a revoked role takes effect within one token lifetime.
 */
final class SessionAuth
{
    public const REFRESH_WINDOW = 60;
    private const PENDING = 'x2w.login';
    private const TOKENS = 'x2w.tokens';
    private const LIFETIME = 'x2w.lifetime';
    private const LOGOUT_HINT = 'x2w.logout_hint';

    private OidcClient|\Closure $oidcOrFactory;
    private ?OidcClient $oidcClient = null;

    /**
     * @param OidcClient|\Closure(): OidcClient $oidc
     */
    public function __construct(
        private Config $config,
        private RuleEvaluator $rules,
        private TenantResolver $tenants,
        OidcClient|\Closure $oidc,
        private Session $session,
        private Log $log,
    ) {
        $this->oidcOrFactory = $oidc;
    }

    private function oidc(): OidcClient
    {
        if ($this->oidcClient !== null) {
            return $this->oidcClient;
        }

        if ($this->oidcOrFactory instanceof OidcClient) {
            $this->oidcClient = $this->oidcOrFactory;
        } else {
            $this->oidcClient = ($this->oidcOrFactory)();
            if (!($this->oidcClient instanceof OidcClient)) {
                throw new \LogicException('OIDC factory must return an OidcClient');
            }
        }

        return $this->oidcClient;
    }

    public function startLogin(): string
    {
        $login = $this->oidc()->beginLogin();
        $this->session->set(self::PENDING, $login['request']->toArray());
        return $login['url'];
    }

    public function finishLogin(string $code, string $state): AuthResult
    {
        $raw = $this->session->get(self::PENDING);
        $this->session->remove(self::PENDING);
        $pending = \is_array($raw) ? LoginRequest::fromArray($raw) : null;
        if ($pending === null) {
            return new AuthResult(AuthOutcome::Expired, 'no pending login');
        }
        $now = \time();
        try {
            $tokens = $this->oidc()->completeLogin($code, $state, $pending, $now);
        } catch (IdpRequestException $e) {
            $this->log->warning('login failed: ' . $e->getMessage());
            return new AuthResult(AuthOutcome::Unavailable, 'identity provider unavailable');
        } catch (LoginFlowException $e) {
            $this->log->info('login failed: ' . $e->getMessage());
            return new AuthResult(AuthOutcome::Expired, 'login flow expired');
        } catch (OidcException $e) {
            $this->log->warning('login failed: ' . $e->getMessage());
            return new AuthResult(AuthOutcome::Denied, 'login failed');
        }
        $denied = $this->denial($tokens->claims);
        if ($denied !== null) {
            $this->log->info('login refused (' . $this->subjectTag($tokens->claims) . '): ' . $denied);
            $this->session->remove(self::TOKENS);
            $this->session->remove(self::LIFETIME);
            $this->session->set(self::LOGOUT_HINT, $tokens->idToken);
            return new AuthResult(AuthOutcome::Denied, $denied);
        }
        $this->session->regenerate();
        $this->session->set(self::TOKENS, $tokens->toArray());
        $this->session->set(self::LIFETIME, \max(1, $tokens->expiresAt - $now));
        $this->session->remove(self::LOGOUT_HINT);
        return new AuthResult(AuthOutcome::Ok);
    }

    /** @param array<string, mixed> $claims */
    private function subjectTag(array $claims): string
    {
        $sub = $claims['sub'] ?? null;
        return \is_string($sub) && $sub !== '' ? 'sub#' . \substr(\hash('sha256', $sub), 0, 12) : 'sub#?';
    }

    public function current(int $now): ?Identity
    {
        $raw = $this->session->get(self::TOKENS);
        $tokens = \is_array($raw) ? TokenSet::fromArray($raw) : null;
        if ($tokens === null) {
            return null;
        }
        $lifetimeRaw = $this->session->get(self::LIFETIME);
        $refreshWindow = (\is_int($lifetimeRaw) && $lifetimeRaw >= 2)
            ? \min(self::REFRESH_WINDOW, \intdiv($lifetimeRaw, 2))
            : self::REFRESH_WINDOW;
        if ($tokens->expiresWithin($refreshWindow, $now)) {
            try {
                $tokens = $this->oidc()->refresh($tokens);
            } catch (IdpRequestException $e) {
                if ($tokens->expiresAt > $now) {
                    // Transient IdP/transport failure and the current access
                    // token still works: keep the session until it expires
                    // rather than ending it on every outage.
                    $this->log->info('refresh failed, keeping session until token expiry: ' . $e::class);
                    return $this->identity($tokens);
                }
                $this->log->info('refresh failed, session ended: ' . $e->getMessage());
                $this->session->destroy();
                return null;
            } catch (OidcException $e) {
                $this->log->info('refresh failed, session ended: ' . $e->getMessage());
                $this->session->destroy();
                return null;
            }
            $denied = $this->denial($tokens->claims);
            if ($denied !== null) {
                $this->log->info('session ended after refresh: ' . $denied);
                $this->session->destroy();
                return null;
            }
            $this->session->set(self::TOKENS, $tokens->toArray());
            $this->session->set(self::LIFETIME, \max(1, $tokens->expiresAt - $now));
        } else {
            // Validate stored claims even on non-refresh path
            $denied = $this->denial($tokens->claims);
            if ($denied !== null) {
                $this->log->info('session ended: ' . $denied);
                $this->session->destroy();
                return null;
            }
        }
        return $this->identity($tokens);
    }

    public function logout(): string
    {
        $raw = $this->session->get(self::TOKENS);
        $tokens = \is_array($raw) ? TokenSet::fromArray($raw) : null;
        $hintRaw = $this->session->get(self::LOGOUT_HINT);
        $hint = \is_string($hintRaw) && $hintRaw !== '' ? $hintRaw : null;
        $this->session->destroy();
        if ($tokens !== null) {
            return $this->oidc()->logoutUrl($tokens) ?? $this->config->baseUrl . '/';
        }
        return $this->oidc()->logoutUrlForHint($hint) ?? $this->config->baseUrl . '/';
    }

    /** @param array<string, mixed> $claims */
    private function denial(array $claims): ?string
    {
        $email = $claims['email'] ?? null;
        if (!\is_string($email) || !\str_contains($email, '@')) {
            return 'token without email';
        }
        if (!$this->rules->matches($this->config->rules[$this->config->loginRequires], $claims)) {
            return 'login rule not met';
        }
        return null;
    }

    private function identity(TokenSet $tokens): Identity
    {
        $claims = $tokens->claims;
        $tenant = $this->tenants->resolve($claims);

        // Log tenant warning only if at least one enabled DAV service needs {tenant}
        if ($tenant->tenant === null) {
            $needsTenant = false;
            foreach ($this->config->dav as $service) {
                if ($service->enabled && \str_contains($service->urlTemplate, '{tenant}')) {
                    $needsTenant = true;
                    break;
                }
            }
            if ($needsTenant) {
                $this->log->warning('no tenant, DAV disabled: ' . $tenant->reason);
            }
        }

        $features = [];
        foreach ($this->config->dav as $name => $service) {
            $needsTenant = \str_contains($service->urlTemplate, '{tenant}');
            $features[$name] = $service->enabled
                && (!$needsTenant || $tenant->tenant !== null)
                && $this->rules->matches($this->config->rules[$service->requires], $claims);
        }
        $uid = Claims::at($claims, 'preferred_username');
        if (!\is_string($uid) || $uid === '') {
            $sub = Claims::at($claims, 'sub');
            $uid = \is_string($sub) && $sub !== '' ? $sub : '';
        }
        return new Identity(
            (string) $claims['email'],
            $uid,
            $tenant->tenant,
            $tokens->accessToken,
            $features,
        );
    }
}
