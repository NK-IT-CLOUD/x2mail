<?php

declare(strict_types=1);

namespace OCA\X2Mail\Controller;

use OCA\X2Mail\Service\ConnectivityCheckService;
use OCA\X2Mail\Service\DomainConfigService;
use OCA\X2Mail\Util\EngineHelper;
use OCA\X2Mail\Util\NavigationTitle;
use OCA\X2Mail\Util\SetupResolvers;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class SetupController extends Controller
{
    use SetupResolvers;

    private const APP_ID = 'x2mail';
    private const OIDC_PROVIDER_KEY = 'oidc-provider';

    public function __construct(
        string $appName,
        IRequest $request,
        private IAppConfig $appConfig,
        private IAppManager $appManager,
        private DomainConfigService $domainService,
        private ISession $session,
        private IUserSession $userSession,
        private IUserConfig $userConfig,
        private LoggerInterface $logger,
        private EngineHelper $engineHelper,
        private ConnectivityCheckService $connectivityCheckService,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Load current setup configuration for the wizard form.
     */
    #[NoCSRFRequired]
    public function getConfig(): JSONResponse
    {
        $domains = $this->domainService->listDomains();
        $domainConfigs = [];

        foreach ($domains as $domain) {
            $raw = $this->domainService->readDomainConfig($domain);
            if (!$raw) {
                continue;
            }

            $domainConfigs[$domain] = [
                'imap_host' => $raw['IMAP']['host'] ?? '',
                'imap_port' => $raw['IMAP']['port'] ?? 143,
                'imap_ssl' => DomainConfigService::sslToString($raw['IMAP']['type'] ?? 0),
                'oidc_audience' => $this->appConfig->getValueString(self::APP_ID, 'oidc-exchange-audience', ''),
                'oidc_scopes' => $this->appConfig->getValueString(self::APP_ID, 'oidc-exchange-scopes', ''),
                'smtp_host' => $raw['SMTP']['host'] ?? '',
                'smtp_port' => $raw['SMTP']['port'] ?? 587,
                'smtp_ssl' => DomainConfigService::sslToString($raw['SMTP']['type'] ?? 0),
                'smtp_auth' => $raw['SMTP']['useAuth'] ?? false,
                'sieve' => $raw['Sieve']['enabled'] ?? false,
                'sieve_host' => $raw['Sieve']['host'] ?? '',
                'sieve_port' => $raw['Sieve']['port'] ?? 4190,
                'sieve_ssl' => DomainConfigService::sslToString($raw['Sieve']['type'] ?? 0),
            ];
        }

        // OIDC status
        $userOidcInstalled = $this->appManager->isEnabledForUser('user_oidc');
        $oidcAutoLogin = $this->appConfig->getValueString(self::APP_ID, 'autologin-oidc', '0');
        $oidcProvider = $userOidcInstalled ? 'user_oidc' : 'none';

        // Suggest domain from admin's email (part after @)
        $suggestedDomain = '';
        $user = $this->userSession->getUser();
        if ($user) {
            $email = $user->getEMailAddress();
            if ($email && \str_contains($email, '@')) {
                $suggestedDomain = \substr($email, \strpos($email, '@') + 1);
            }
        }

        return new JSONResponse([
            'domains' => $domainConfigs,
            'suggested_domain' => $suggestedDomain,
            'single_domain_mode' => true,
            'domain_count' => \count($domainConfigs),
            'oidc' => [
                'enabled' => $oidcAutoLogin === '1',
                'provider' => $oidcProvider,
                'user_oidc' => $userOidcInstalled,
            ],
        ]);
    }

    /**
     * Run preflight checks against IMAP/SMTP/OIDC.
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function preflightCheck(
        string $imap_host = '',
        int $imap_port = 143,
        string $imap_ssl = 'none',
        string $smtp_host = '',
        int $smtp_port = 587,
        string $smtp_ssl = 'none',
        bool $sieve = false,
        string $sieve_host = '',
        int $sieve_port = 4190,
        string $sieve_ssl = 'none',
        string $oidc_provider = 'user_oidc'
    ): JSONResponse {
        $imapHost = $imap_host;
        $imapPort = $imap_port;
        $imapSsl = $this->normalizeSslMode($imap_ssl);
        $smtpHost = $smtp_host ?: $imap_host;
        $smtpPort = $smtp_port;
        $smtpSsl = $this->normalizeSslMode($smtp_ssl);
        $sieveHost = \trim($sieve_host) ?: $imap_host;
        $sieveSsl = $this->normalizeSslMode($sieve_ssl);

        if ($imapPort < 1 || $imapPort > 65535) {
            return new JSONResponse(['error' => 'Invalid IMAP port'], 400);
        }
        if ($smtpPort < 1 || $smtpPort > 65535) {
            return new JSONResponse(['error' => 'Invalid SMTP port'], 400);
        }

        // Validate hostname format — prevent injection of special characters
        if ($imapHost !== '' && !\preg_match('/\A[a-zA-Z0-9.\-]+\z/', $imapHost)) {
            return new JSONResponse(['error' => 'Invalid IMAP hostname'], 400);
        }
        if ($smtpHost !== '' && !\preg_match('/\A[a-zA-Z0-9.\-]+\z/', $smtpHost)) {
            return new JSONResponse(['error' => 'Invalid SMTP hostname'], 400);
        }

        $userOidc = $this->appManager->isEnabledForUser('user_oidc');
        $requestedProvider = $this->normalizeOidcProvider($oidc_provider);
        if ($requestedProvider === null) {
            return new JSONResponse(['error' => 'Invalid OIDC provider'], 400);
        }
        $resolvedProvider = $userOidc ? 'user_oidc' : 'none';

        $results = [];

        // IMAP check
        $imapResult = $this->connectivityCheckService->checkImap($imapHost, $imapPort, $imapSsl);
        $results['imap'] = $imapResult;

        // OAuth capability check
        if ($imapResult['connected']) {
            $hasOAuth = false;
            $authMethods = [];
            foreach ($imapResult['capabilities'] as $cap) {
                if (\str_starts_with($cap, 'AUTH=')) {
                    $method = \substr($cap, 5);
                    $authMethods[] = $method;
                    if ($method === 'OAUTHBEARER' || $method === 'XOAUTH2') {
                        $hasOAuth = true;
                    }
                }
            }
            $results['imap']['oauth_supported'] = $hasOAuth;
            $results['imap']['auth_methods'] = $authMethods;
        }

        // SMTP check
        $results['smtp'] = $this->connectivityCheckService->checkSmtp($smtpHost, $smtpPort, $smtpSsl);
        if ($results['smtp']['connected']) {
            $smtpAuthMethods = $results['smtp']['auth_methods'];
            $smtpHasOAuth = \in_array('OAUTHBEARER', $smtpAuthMethods, true)
                || \in_array('XOAUTH2', $smtpAuthMethods, true);
            $results['smtp']['oauth_supported'] = $smtpHasOAuth;
        }

        // Sieve check (only when filtering is enabled)
        if ($sieve) {
            if ($sieve_port < 1 || $sieve_port > 65535) {
                return new JSONResponse(['error' => 'Invalid Sieve port'], 400);
            }
            if ($sieveHost !== '' && !\preg_match('/\A[a-zA-Z0-9.\-]+\z/', $sieveHost)) {
                return new JSONResponse(['error' => 'Invalid Sieve hostname'], 400);
            }
            $results['sieve'] = $this->connectivityCheckService->checkSieve($sieveHost, $sieve_port, $sieveSsl);
        }

        // OIDC check
        $oidcResult = [
            'user_oidc' => $userOidc,
            'any_installed' => $userOidc,
            'requested_provider' => $requestedProvider,
            'provider' => $resolvedProvider,
            'provider_fallback' => $requestedProvider !== $resolvedProvider,
        ];

        if ($resolvedProvider === 'user_oidc') {
            $storeToken = $this->appConfig->getValueString('user_oidc', 'store_login_token', '0');
            $oidcResult['store_login_token'] = $storeToken === '1';

            // Check if at least one OIDC provider is configured
            try {
                $oidcResult['provider_configured'] = $this->appConfig->getValueString(
                    'user_oidc',
                    'provider-1-mappingUid',
                    '',
                    lazy: true
                ) !== '';
            } catch (\Throwable $e) {
                $oidcResult['provider_configured'] = null;
            }
        }

        // Session checks (only available in browser, not occ)
        $oidcResult['session_is_oidc'] = (bool) $this->session->get('is_oidc');
        $oidcResult['session_has_token'] = (bool) $this->session->get('oidc_access_token');

        // Decode JWT payload for admin diagnostics (no signature verification needed)
        $accessToken = $this->session->get('oidc_access_token');
        if (\is_string($accessToken)) {
            $claims = $this->decodeJwtClaims($accessToken);
            if ($claims !== null) {
                $oidcResult['token'] = $claims;
            }
        }

        $results['oidc'] = $oidcResult;

        return new JSONResponse($results);
    }

    /**
     * Decode a JWT payload for admin diagnostics (no signature verification needed).
     *
     * @return array<string, mixed>|null
     */
    private function decodeJwtClaims(string $token): ?array
    {
        $parts = \explode('.', $token);
        if (\count($parts) !== 3) {
            return null;
        }
        $b64 = \strtr($parts[1], '-_', '+/');
        $b64 .= \str_repeat('=', (4 - \strlen($b64) % 4) % 4);
        $payload = \json_decode(\base64_decode($b64), true);
        if (!\is_array($payload)) {
            return null;
        }
        return [
            'email' => $payload['email'] ?? null,
            'aud' => $payload['aud'] ?? null,
            'iss' => $payload['iss'] ?? null,
            'exp' => $payload['exp'] ?? null,
            'expires_in' => isset($payload['exp']) ? $payload['exp'] - \time() : null,
            'scope' => $payload['scope'] ?? null,
        ];
    }

    /**
     * Save setup configuration (create or update domain).
     */
    public function saveSetup(
        string $domain = '',
        string $imap_host = '',
        int $imap_port = 143,
        string $imap_ssl = 'none',
        string $oidc_audience = '',
        string $oidc_scopes = '',
        string $smtp_host = '',
        int $smtp_port = 587,
        string $smtp_ssl = 'none',
        bool $sieve = false,
        string $sieve_host = '',
        int $sieve_port = 4190,
        string $sieve_ssl = 'none',
        string $oidc_provider = 'user_oidc'
    ): JSONResponse {
        $domain = \trim($domain);
        $imapHost = \trim($imap_host);
        $imapPort = $imap_port;
        $imapSsl = $this->normalizeSslMode($imap_ssl);
        $smtpHost = \trim($smtp_host) ?: $imapHost;
        $smtpPort = $smtp_port;
        $smtpSsl = $this->normalizeSslMode($smtp_ssl);
        $sieveHost = \trim($sieve_host) ?: $imapHost;
        $sievePort = $sieve_port;
        $sieveSsl = $this->normalizeSslMode($sieve_ssl);
        $requestedProvider = $this->normalizeOidcProvider($oidc_provider);

        // Validation
        if ($domain === '') {
            return new JSONResponse(['status' => 'error', 'message' => 'Domain is required'], 400);
        }
        if (!\preg_match('/\A[a-zA-Z0-9.\-]+\z/', $domain)) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid domain name'], 400);
        }
        if ($imapHost === '') {
            return new JSONResponse(['status' => 'error', 'message' => 'IMAP host is required'], 400);
        }
        if (!\preg_match('/\A[a-zA-Z0-9.\-]+\z/', $imapHost)) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid IMAP hostname'], 400);
        }
        if ($smtpHost !== '' && !\preg_match('/\A[a-zA-Z0-9.\-]+\z/', $smtpHost)) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid SMTP hostname'], 400);
        }
        if ($sieveHost !== '' && !\preg_match('/\A[a-zA-Z0-9.\-]+\z/', $sieveHost)) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid Sieve hostname'], 400);
        }
        if ($imapPort < 1 || $imapPort > 65535) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid IMAP port'], 400);
        }
        if ($smtpPort < 1 || $smtpPort > 65535) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid SMTP port'], 400);
        }
        if ($sievePort < 1 || $sievePort > 65535) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid Sieve port'], 400);
        }
        if ($requestedProvider === null) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid OIDC provider'], 400);
        }
        if (!\in_array($imapSsl, ['none', 'ssl', 'starttls'], true)) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid IMAP SSL mode'], 400);
        }
        if (!\in_array($smtpSsl, ['none', 'ssl', 'starttls'], true)) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid SMTP SSL mode'], 400);
        }
        if (!\in_array($sieveSsl, ['none', 'ssl', 'starttls'], true)) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid Sieve SSL mode'], 400);
        }
        $audienceTooLong = $oidc_audience !== '' && \strlen($oidc_audience) > 255;
        $audienceInvalid = $oidc_audience !== '' && !\preg_match('/\A[A-Za-z0-9._:\/\-]+\z/', $oidc_audience);
        if ($audienceTooLong || $audienceInvalid) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid IMAP audience'], 400);
        }
        $oidc_scopes = \trim($oidc_scopes);
        $scopesInvalid = \strlen($oidc_scopes) > 255
            || ($oidc_scopes !== '' && !\preg_match('/\A[A-Za-z0-9._:\/\- ]+\z/', $oidc_scopes));
        if ($scopesInvalid) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid token exchange scopes'], 400);
        }

        try {
            $userOidcInstalled = $this->appManager->isEnabledForUser('user_oidc');
            if (!$userOidcInstalled) {
                return new JSONResponse(['status' => 'error', 'message' => 'user_oidc is not enabled'], 400);
            }
            $resolvedProvider = 'user_oidc';

            $domainConfig = $this->domainService->buildDomainConfig(
                $imapHost,
                $imapPort,
                $imapSsl,
                $smtpHost,
                $smtpPort,
                $smtpSsl,
                $sieveHost,
                $sievePort,
                $sieveSsl,
                $sieve,
            );
            $this->domainService->writeDomainConfig($domain, $domainConfig);

            $removedDomains = [];
            $cleanupWarnings = [];
            // Single-domain mode: write first, then consolidate old configs.
            foreach ($this->domainService->listDomains() as $existing) {
                if ($existing === $domain) {
                    continue;
                }

                try {
                    $this->domainService->deleteDomainConfig($existing);
                    $removedDomains[] = $existing;
                } catch (\Throwable $cleanupError) {
                    $cleanupWarnings[] = $existing;
                    $this->logger->warning(
                        'X2Mail saveSetup cleanup failed for domain "' . $existing . '": ' . $cleanupError->getMessage()
                    );
                }
            }

            // Set app config for OIDC auto-login
            $this->appConfig->setValueString(self::APP_ID, 'autologin', '1');
            $this->appConfig->setValueString(self::APP_ID, 'autologin-oidc', '1');
            $this->appConfig->setValueString(self::APP_ID, self::OIDC_PROVIDER_KEY, $resolvedProvider);
            $this->appConfig->setValueString(self::APP_ID, 'oidc-exchange-audience', \trim($oidc_audience));
            $this->appConfig->setValueString(self::APP_ID, 'oidc-exchange-scopes', $oidc_scopes);

            // Ensure store_login_token is set for user_oidc
            $this->appConfig->setValueString('user_oidc', 'store_login_token', '1');

            // Set engine config for this auth mode
            try {
                $this->engineHelper->loadApp();
                $oConfig = \X2Mail\Engine\Api::Config();
                $oConfig->Set('login', 'default_domain', $domain);
                $oConfig->Set('webmail', 'allow_additional_identities', true);
                $oConfig->Set('imap', 'show_login_alert', false);
                $oConfig->Set('defaults', 'autologout', 15);
                $oConfig->Set('defaults', 'contacts_autosave', false);
                $oConfig->Set('webmail', 'allow_additional_accounts', false);
                $oConfig->Save();

                // Invalidate stale auth: engine session + stored credentials
                \X2Mail\Engine\Api::Actions()->Logout(true);

                // Clean up any stored per-user plain credentials
                $this->userConfig->deleteKey('x2mail', 'passphrase');
                $this->userConfig->deleteKey('x2mail', 'email');
            } catch (\Throwable $e) {
                // Non-fatal
            }

            return new JSONResponse([
                'status' => 'success',
                'message' => $removedDomains === []
                    ? "Domain '{$domain}' saved"
                    : "Domain '{$domain}' saved and replaced " . \count($removedDomains) . ' previous domain(s)',
                'removed_domains' => $removedDomains,
                'cleanup_warnings' => $cleanupWarnings,
                'provider' => $resolvedProvider,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('X2Mail saveSetup failed: ' . $e->getMessage());
            return new JSONResponse(['status' => 'error', 'message' => 'Save failed'], 500);
        }
    }

    /**
     * Perform a real SSO OAUTHBEARER authentication test against IMAP/SMTP/Sieve.
     * Uses the admin's live OIDC access token — admin-only (no NoAdminRequired).
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function testAuth(
        string $imap_host = '',
        int $imap_port = 143,
        string $imap_ssl = 'none',
        string $oidc_audience = '',
        string $oidc_scopes = '',
        string $smtp_host = '',
        int $smtp_port = 587,
        string $smtp_ssl = 'none',
        bool $sieve = false,
        string $sieve_host = '',
        int $sieve_port = 4190,
        string $sieve_ssl = 'none'
    ): JSONResponse {
        $imapHost = \trim($imap_host);
        $smtpHost = \trim($smtp_host) ?: $imapHost;
        $sieveHost = \trim($sieve_host) ?: $imapHost;
        $imapSsl = $this->normalizeSslMode($imap_ssl);
        $smtpSsl = $this->normalizeSslMode($smtp_ssl);
        $sieveSsl = $this->normalizeSslMode($sieve_ssl);

        if ($imap_port < 1 || $imap_port > 65535) {
            return new JSONResponse(['error' => 'Invalid IMAP port'], 400);
        }
        if ($smtp_port < 1 || $smtp_port > 65535) {
            return new JSONResponse(['error' => 'Invalid SMTP port'], 400);
        }
        if ($sieve_port < 1 || $sieve_port > 65535) {
            return new JSONResponse(['error' => 'Invalid Sieve port'], 400);
        }
        if ($imapHost !== '' && !\preg_match('/\A[a-zA-Z0-9.\-]+\z/', $imapHost)) {
            return new JSONResponse(['error' => 'Invalid IMAP hostname'], 400);
        }
        if ($smtpHost !== '' && !\preg_match('/\A[a-zA-Z0-9.\-]+\z/', $smtpHost)) {
            return new JSONResponse(['error' => 'Invalid SMTP hostname'], 400);
        }
        if ($sieveHost !== '' && !\preg_match('/\A[a-zA-Z0-9.\-]+\z/', $sieveHost)) {
            return new JSONResponse(['error' => 'Invalid Sieve hostname'], 400);
        }
        if (!\in_array($sieveSsl, ['none', 'ssl', 'starttls'], true)) {
            return new JSONResponse(['error' => 'Invalid Sieve SSL mode'], 400);
        }
        $audience = \trim($oidc_audience);
        $audienceTooLong = $audience !== '' && \strlen($audience) > 255;
        $audienceInvalid = $audience !== '' && !\preg_match('/\A[A-Za-z0-9._:\/\-]+\z/', $audience);
        if ($audienceTooLong || $audienceInvalid) {
            return new JSONResponse(['error' => 'Invalid IMAP audience'], 400);
        }
        $scopes = \trim($oidc_scopes);
        if ($scopes !== '' && (\strlen($scopes) > 255 || !\preg_match('/\A[A-Za-z0-9._:\/\- ]+\z/', $scopes))) {
            return new JSONResponse(['error' => 'Invalid token exchange scopes'], 400);
        }

        // Test with the audience/scopes typed in the wizard (even if not yet saved).
        $token = $this->engineHelper->getOidcAccessToken($audience, $scopes);
        if ($token === null) {
            return new JSONResponse(['error' => 'No active SSO token — log in via SSO first'], 400);
        }

        // Report which token the test actually used (exchange vs login token).
        $loginToken = $this->session->get('oidc_access_token');
        $tokenInfo = $this->decodeJwtClaims($token) ?? [];
        $tokenInfo['audience_requested'] = $audience;
        $tokenInfo['scopes_requested'] = $scopes;
        $tokenInfo['exchanged'] = $audience !== '' && $token !== $loginToken;

        // Resolve the user email for OAUTHBEARER (n,a=<email>,...)
        $user = $this->userSession->getUser();
        $email = $user?->getEMailAddress() ?? '';
        if ($email === '' && $user !== null) {
            $email = $this->userConfig->getValueString($user->getUID(), 'settings', 'email');
        }

        $results = [];
        $results['imap'] = $this->connectivityCheckService->authCheckImap(
            $imapHost,
            $imap_port,
            $imapSsl,
            $email,
            $token
        );
        $results['smtp'] = $this->connectivityCheckService->authCheckSmtp(
            $smtpHost,
            $smtp_port,
            $smtpSsl,
            $email,
            $token
        );

        if ($sieve) {
            $results['sieve'] = $this->connectivityCheckService->authCheckSieve(
                $sieveHost,
                $sieve_port,
                $sieveSsl,
                $email,
                $token
            );
        }

        $results['token'] = $tokenInfo;

        return new JSONResponse($results);
    }

    /**
     * Persist Allgemein and/or Erweitert admin settings (partial POST bodies supported).
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function saveAdminSettings(
        int $attachment_size_limit = 25,
        bool $show_attachment_thumbnail = true,
        bool $openpgp = true,
        bool $gnupg = true,
        bool $force_nc_lang = false,
        string $app_path = '',
        bool $engine_debug = false,
        bool $x2mail_debug = false,
        string $menu_title = '',
    ): JSONResponse {
        $params = $this->request->getParams();
        $has = static fn (string $key): bool => \array_key_exists($key, $params);

        if ($has('menu_title')) {
            $menuError = NavigationTitle::validate($menu_title);
            if ($menuError !== null) {
                return new JSONResponse(['error' => $menuError], 400);
            }
            $this->appConfig->setValueString(
                self::APP_ID,
                NavigationTitle::APP_CONFIG_KEY,
                \trim($menu_title)
            );
        }

        if ($has('attachment_size_limit')) {
            if ($attachment_size_limit < 1 || $attachment_size_limit > 2048) {
                return new JSONResponse(['error' => 'Invalid attachment size limit'], 400);
            }
        }

        $engineKeys = [
            'attachment_size_limit',
            'show_attachment_thumbnail',
            'openpgp',
            'gnupg',
            'force_nc_lang',
            'app_path',
            'engine_debug',
        ];
        $touchEngine = false;
        foreach ($engineKeys as $key) {
            if ($has($key)) {
                $touchEngine = true;
                break;
            }
        }

        if ($touchEngine) {
            $this->engineHelper->loadApp();
            $oConfig = \X2Mail\Engine\Api::Config();

            if ($has('attachment_size_limit')) {
                $oConfig->Set('webmail', 'attachment_size_limit', $attachment_size_limit);
            }
            if ($has('show_attachment_thumbnail')) {
                $oConfig->Set('interface', 'show_attachment_thumbnail', $show_attachment_thumbnail);
            }
            if ($has('openpgp')) {
                $oConfig->Set('security', 'openpgp', $openpgp);
            }
            if ($has('gnupg')) {
                $oConfig->Set('security', 'gnupg', $gnupg);
            }
            if ($has('force_nc_lang')) {
                // Force NC language overrides engine 'allow_languages_on_settings' (inverse logic)
                $oConfig->Set('webmail', 'allow_languages_on_settings', !$force_nc_lang);
            }
            if ($has('app_path')) {
                $appPath = \trim($app_path);
                if (
                    $appPath !== ''
                    && \str_starts_with($appPath, '/')
                    && !\str_contains($appPath, '://')
                    && !\str_contains($appPath, '..')
                ) {
                    $oConfig->Set('webmail', 'app_path', \preg_replace('#/+#', '/', $appPath));
                } elseif ($appPath === '') {
                    $oConfig->Set('webmail', 'app_path', '');
                }
            }
            if ($has('engine_debug')) {
                $oConfig->Set('debug', 'enable', $engine_debug);
            }
            $oConfig->Save();
        }

        if ($has('x2mail_debug')) {
            $this->appConfig->setValueString(self::APP_ID, 'debug_log', $x2mail_debug ? '1' : '0');
        }

        return new JSONResponse(['status' => 'ok']);
    }

    /**
     * Delete a domain configuration.
     */
    public function deleteDomain(string $domain = ''): JSONResponse
    {
        $domain = \trim($domain);
        if ($domain === '') {
            return new JSONResponse(['status' => 'error', 'message' => 'Domain is required'], 400);
        }
        if (!\preg_match('/\A[a-zA-Z0-9.\-]+\z/', $domain)) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid domain name'], 400);
        }

        try {
            $this->domainService->deleteDomainConfig($domain);
            return new JSONResponse(['status' => 'success', 'message' => "Domain '{$domain}' deleted"]);
        } catch (\Throwable $e) {
            $this->logger->error('X2Mail deleteDomain failed: ' . $e->getMessage());
            return new JSONResponse(['status' => 'error', 'message' => 'Delete failed'], 500);
        }
    }
}
