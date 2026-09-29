<?php

namespace OCA\X2Mail\Util;

use OCA\X2Mail\Listeners\OidcEmailClaimListener;
use OCP\App\IAppManager;
use OCP\Config\IUserConfig;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\ISession;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class EngineHelper
{
    public function __construct(
        private IConfig $config,
        private IAppConfig $appConfig,
        private IUserConfig $userConfig,
        private ISession $session,
        private IUserSession $userSession,
        private IAppManager $appManager,
        private LoggerInterface $logger,
        private IEventDispatcher $eventDispatcher,
    ) {
    }

    public function loadApp(): void
    {
        if (\class_exists('X2Mail\\Engine\\Api')) {
            return;
        }

        // X2Mail namespace autoloader (case-sensitive PSR-4 style)
        \spl_autoload_register(function ($sClassName) {
            if (\str_starts_with($sClassName, 'X2Mail\\')) {
                $file = X2MAIL_LIBRARIES_PATH . \strtr($sClassName, '\\', DIRECTORY_SEPARATOR) . '.php';
                if (\is_file($file)) {
                    include_once $file;
                }
            }
        });

        // Lowercase-filename autoloader for X2Mail\Engine
        \spl_autoload_register(function ($sClassName) {
            if (\str_starts_with($sClassName, 'X2Mail\\Engine\\')) {
                $file = X2MAIL_LIBRARIES_PATH . 'X2Mail/Engine/'
                    . \strtolower(\strtr(\substr($sClassName, 14), '\\', DIRECTORY_SEPARATOR))
                    . '.php';
                if (\is_file($file)) {
                    include_once $file;
                    return;
                }
                $parts = \explode('\\', \substr($sClassName, 14));
                $fileName = \array_pop($parts);
                $dirPath = \implode(DIRECTORY_SEPARATOR, \array_map('strtolower', $parts));
                $file = X2MAIL_LIBRARIES_PATH . 'X2Mail/Engine/'
                    . ($dirPath ? $dirPath . DIRECTORY_SEPARATOR : '')
                    . $fileName . '.php';
                if (\is_file($file)) {
                    include_once $file;
                }
            }
        });

        $_ENV['X2MAIL_INCLUDE_AS_API'] = true;

        if (!\defined('APP_DATA_FOLDER_PATH')) {
            $dataDir = \rtrim(\trim($this->config->getSystemValue('datadirectory', '')), '\\/');
            \define('APP_DATA_FOLDER_PATH', $dataDir . '/appdata_x2mail/');
        }

        $app_dir = \dirname(\dirname(__DIR__)) . '/app';
        $index = $app_dir . '/index.php';
        if (!\is_readable($index)) {
            $this->logger->warning('X2Mail: app/index.php not readable, skipping engine bootstrap');
            return;
        }
        require_once $index;

        $this->registerHostBridge();
    }

    /**
     * Registers this Nextcloud instance as the engine host. The autoloaders
     * above need X2MAIL_LIBRARIES_PATH, which only the engine's include.php
     * defines, so loadApp() calls this after requiring the engine index.
     */
    public function registerHostBridge(): void
    {
        \X2Mail\Engine\Host::set(new NextcloudBridge($this));
    }

    public function startApp(bool $handle = false): void
    {
        $this->loadApp();

        $oConfig = \X2Mail\Engine\Api::Config();

        if (false !== \stripos(\php_sapi_name(), 'cli')) {
            return;
        }

        try {
            $oActions = \X2Mail\Engine\Api::Actions();
            $doLogin = !$oActions->getMainAccountFromToken(false);
            $aCredentials = $this->getLoginCredentials();
            if ($doLogin && $aCredentials[1] && $aCredentials[2]) {
                try {
                    $oActions->LoginProcess(
                        $aCredentials[1],
                        new \X2Mail\Engine\SensitiveString($aCredentials[2])
                    );
                } catch (\X2Mail\Engine\Exceptions\ClientException $e) {
                    // OIDC login failure — no credentials to clear
                    $this->logger->debug('X2Mail SSO login failed: ' . $e->getMessage());
                } catch (\Throwable $e) {
                    // Non-login errors — don't touch credentials
                    $this->logger->warning('X2Mail engine login error: ' . $e->getMessage());
                }
            }

            if ($handle) {
                \header_remove('Content-Security-Policy');
                \X2Mail\Engine\Service::Handle();
                exit;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('X2Mail engine bootstrap error: ' . $e->getMessage());
        }
    }

    /**
     * Whether the engine currently has an authenticated main account.
     * Call after startApp() — the result is cached by the engine, so this
     * reflects the outcome of the SSO auto-login attempt without side effects.
     */
    public function hasAuthenticatedAccount(): bool
    {
        if (!\class_exists('X2Mail\\Engine\\Api')) {
            return false;
        }
        try {
            return \X2Mail\Engine\Api::Actions()->getMainAccountFromToken(false) !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Returns the SSO uid stored in the NC session, or null if not set.
     */
    public function getSsoUid(): ?string
    {
        $uid = $this->session->get('x2mail-uid');
        return \is_string($uid) && $uid !== '' ? $uid : null;
    }

    /**
     * Returns the current Nextcloud session id, or null if unavailable.
     *
     * Stable per session (changes only on NC session regeneration), it is the
     * per-session secret the engine derives its connection/CSRF token from in
     * place of the former self-set x2mtoken cookie. getId() throws when no
     * session is active (e.g. CLI), so we guard and return null.
     */
    public function getNcSessionId(): ?string
    {
        try {
            $id = $this->session->getId();
        } catch (\Throwable $e) {
            return null;
        }
        return $id !== '' ? $id : null;
    }

    /**
     * Returns the engine identity (mail address) of the current SSO user. It
     * selects the engine storage (settings, identities, keys), so it must not
     * be something the user can edit:
     *   1. admin override: IUserConfig x2mail/email. Only an admin can set it
     *      (occ user:setting <uid> x2mail email <address>); users cannot, as
     *      X2Mail accepts no user preference writes for it. Wins over the token.
     *   2. the email claim user_oidc validated and mapped at login
     *      (OidcEmailClaimListener); if user_oidc does not provision users, it
     *      dispatches no mapping event and the claim is read from the ID token
     *      it verified and kept in the session (getIdTokenEmail).
     *      The profile email (settings/email) never
     *      selects the account: users can edit it unless
     *      allow_user_to_change_email is false. A different profile email
     *      (e.g. an alias) is only logged; the claim still applies.
     * Sender identities (additional From addresses, aliases) are separate and
     * unaffected; the mail server decides which From a login may use.
     * Returns null when there is no SSO uid or no validated claim in this
     * session (e.g. a session from before the claim was recorded).
     */
    public function getSsoEmail(): ?string
    {
        $uid = $this->getSsoUid();
        if ($uid === null) {
            return null;
        }

        $custom = $this->userConfig->getValueString($uid, 'x2mail', 'email', '');
        if ($custom !== '') {
            return $custom;
        }

        $claim = $this->session->get(OidcEmailClaimListener::SESSION_KEY);
        if ((!\is_string($claim) || $claim === '') && !$this->userOidcProvisions()) {
            $claim = $this->getIdTokenEmail($uid);
        }
        if (!\is_string($claim) || $claim === '') {
            $this->logger->warning(
                'No validated OIDC email claim in the session of "' . $uid . '"; mail access refused '
                . '(sign in again via SSO; the ID token must carry the mapped email claim)'
            );
            return null;
        }

        $profile = $this->userConfig->getValueString($uid, 'settings', 'email', '');
        if ($profile !== '' && $profile !== $claim) {
            $this->logger->info(
                'Profile email "' . $profile . '" of "' . $uid . '" differs from the validated OIDC email claim "'
                . $claim . '"; using the claim'
            );
        }

        return $claim;
    }

    /**
     * Same test as user_oidc's LoginController::code(): provisioning is on unless
     * 'user_oidc' => ['auto_provision' => false]. Only with provisioning does
     * user_oidc dispatch AttributeMappedEvent (OidcEmailClaimListener).
     */
    private function userOidcProvisions(): bool
    {
        $oidcConfig = $this->config->getSystemValue('user_oidc', []);
        return !\is_array($oidcConfig) || !isset($oidcConfig['auto_provision']) || (bool) $oidcConfig['auto_provision'];
    }

    /**
     * Email from the ID token user_oidc keeps in the session, for logins without
     * provisioning (no AttributeMappedEvent). LoginController::code() is the only
     * writer of 'oidc.id_token' and stores it after it verified the signature
     * (JWKS), exp, iss, aud, azp and nonce, so the payload is read as is. Claim
     * names follow the provider's user_oidc mapping (plain claim names; nested
     * mappings are not resolved and refuse). The token's uid claim must name the
     * session user, so a token left in a session that changed hands (impersonate)
     * is not used.
     */
    private function getIdTokenEmail(string $uid): ?string
    {
        $idToken = $this->session->get('oidc.id_token');
        $providerId = $this->session->get('oidc.providerid');
        if (!\is_string($idToken) || !\is_int($providerId)) {
            return null;
        }
        $parts = \explode('.', $idToken);
        $payload = \count($parts) === 3
            ? \json_decode((string) \base64_decode(\strtr($parts[1], '-_', '+/'), true), true)
            : null;
        if (!\is_array($payload)) {
            return null;
        }

        $mapping = fn (string $setting, string $default): string => $this->appConfig->getValueString(
            'user_oidc',
            'provider-' . $providerId . '-' . $setting,
            '',
            true
        ) ?: $default;

        $tokenUid = $payload[$mapping('mappingUid', 'sub')] ?? null;
        if (!\is_string($tokenUid) || \mb_strtolower($tokenUid) !== \mb_strtolower($uid)) {
            $this->logger->warning('The OIDC ID token in the session does not belong to "' . $uid . '"; not used');
            return null;
        }

        $email = $payload[$mapping('mappingEmail', 'email')] ?? null;
        return \is_string($email) && \trim($email) !== '' ? \mb_strtolower(\trim($email)) : null;
    }

    /**
     * Whether users may edit their own Nextcloud profile email (NC default: yes).
     */
    public function usersCanChangeEmail(): bool
    {
        return $this->config->getSystemValueBool(
            'allow_user_to_change_email',
            $this->config->getSystemValueBool('allow_user_to_change_display_name', true)
        );
    }

    public function isOIDCLogin(): bool
    {
        if ($this->appConfig->getValueString('x2mail', 'autologin-oidc', '0') !== '0') {
            if ($this->appManager->isEnabledForUser('user_oidc')) {
                if ($this->session->get('is_oidc')) {
                    if ($this->session->get('oidc_access_token')) {
                        return true;
                    }
                    \X2Mail\Engine\Log::debug('Nextcloud', 'OIDC access_token missing');
                } else {
                    \X2Mail\Engine\Log::debug('Nextcloud', 'No OIDC login');
                }
            } else {
                \X2Mail\Engine\Log::debug('Nextcloud', 'OIDC login disabled');
            }
        }
        return false;
    }

    /**
     * Single source for the OIDC access token used for IMAP/SMTP OAUTHBEARER.
     * With an audience configured only the exchanged token is used (null if the
     * exchange fails). Without one: fresh login token via user_oidc public
     * event -> cached session value (last resort).
     *
     * Pass $audienceOverride / $scopesOverride (e.g. from the setup wizard
     * Test Login) to use the typed values instead of the stored ones; null
     * falls back to config.
     */
    public function getOidcAccessToken(?string $audienceOverride = null, ?string $scopesOverride = null): ?string
    {
        $audience = $audienceOverride
            ?? $this->appConfig->getValueString('x2mail', 'oidc-exchange-audience', '');
        if ($audience !== '') {
            $rawScopes = $scopesOverride
                ?? $this->appConfig->getValueString('x2mail', 'oidc-exchange-scopes', '');
            $scopes = \preg_split('/\s+/', \trim($rawScopes), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $exchanged = $this->dispatchTokenEvent(
                'OCA\\UserOIDC\\Event\\ExchangedTokenRequestedEvent',
                $audience,
                $scopes
            );
            if ($exchanged !== null) {
                return $exchanged;
            }
            // Fail closed: the login token carries a broader audience than the
            // mail server should see (RFC 9700 section 2.3), so it is not sent instead.
            $this->logger->error(
                'OIDC token exchange for audience "' . $audience . '" yielded no token; '
                . 'mail login refused (no fallback to the login token)'
            );
            return null;
        }

        $fresh = $this->dispatchTokenEvent('OCA\\UserOIDC\\Event\\ExternalTokenRequestedEvent', null);
        if ($fresh !== null) {
            return $fresh;
        }

        $sessionToken = $this->session->get('oidc_access_token');
        return \is_string($sessionToken) && $sessionToken !== '' ? $sessionToken : null;
    }

    /** @param list<string> $extraScopes */
    private function dispatchTokenEvent(string $eventClass, ?string $audienceArg, array $extraScopes = []): ?string
    {
        if (!\class_exists($eventClass)) {
            return null;
        }
        try {
            if ($audienceArg === null) {
                $event = new $eventClass();
            } elseif ($extraScopes === []) {
                $event = new $eventClass($audienceArg);
            } else {
                $event = new $eventClass($audienceArg, $extraScopes);
            }
            if (!$event instanceof Event) {
                return null;
            }
            $this->eventDispatcher->dispatchTyped($event);
            if (!\method_exists($event, 'getToken')) {
                return null;
            }
            $token = $event->getToken();
            if (!\is_object($token) || !\method_exists($token, 'getAccessToken')) {
                return null;
            }
            $access = $token->getAccessToken();
            if (!\is_string($access) || $access === '') {
                return null;
            }
            if (\method_exists($token, 'getExpiresInFromNow')) {
                // Visibility for the known "user_oidc reports expires_in=0" realm issue
                $this->logger->debug(
                    'OIDC token (' . $eventClass . ') expires in '
                    . (int)$token->getExpiresInFromNow() . 's'
                );
            }
            return $access;
        } catch (\Throwable $e) {
            $message = 'OIDC token event failed (' . $eventClass . '): ' . $e->getMessage();
            // user_oidc's GetExternalTokenFailedException / TokenExchangeFailedException
            // carry the IdP error response — surface it for diagnosis.
            if (\method_exists($e, 'getError') && \method_exists($e, 'getErrorDescription')) {
                $error = $e->getError();
                $description = $e->getErrorDescription();
                if (\is_string($error) || \is_string($description)) {
                    $message .= ' — IdP: ' . (\is_string($error) ? $error : '')
                        . ' (' . (\is_string($description) ? $description : '') . ')';
                }
            }
            $this->logger->warning($message);
            return null;
        }
    }

    /** @return array{string, string, string} */
    private function getLoginCredentials(): array
    {
        $sUID = $this->userSession->getUser()->getUID();
        if ($this->session->get('x2mail-uid') === $sUID && $this->isOIDCLogin()) {
            $sEmail = $this->getSsoEmail() ?? '';
            return [$sUID, $sEmail, "oidc_login|{$sUID}"];
        }
        return [$sUID, '', ''];
    }
}
