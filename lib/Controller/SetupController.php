<?php

declare(strict_types=1);

namespace OCA\X2Mail\Controller;

use OCA\X2Mail\Service\DomainConfigService;
use OCA\X2Mail\Util\EngineHelper;
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
    private const APP_ID = 'x2mail';

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

            $imapSasl = $raw['IMAP']['sasl'] ?? [];
            $hasOAuth = \in_array('OAUTHBEARER', $imapSasl) || \in_array('XOAUTH2', $imapSasl);
            $authType = $hasOAuth ? 'oauth' : 'plain';

            $domainConfigs[$domain] = [
                'imap_host' => $raw['IMAP']['host'] ?? '',
                'imap_port' => $raw['IMAP']['port'] ?? 143,
                'imap_ssl' => DomainConfigService::sslToString($raw['IMAP']['type'] ?? 0),
                'smtp_host' => $raw['SMTP']['host'] ?? '',
                'smtp_port' => $raw['SMTP']['port'] ?? 25,
                'smtp_ssl' => DomainConfigService::sslToString($raw['SMTP']['type'] ?? 0),
                'smtp_auth' => $raw['SMTP']['useAuth'] ?? false,
                'auth_type' => $authType,
                'sieve' => $raw['Sieve']['enabled'] ?? false,
            ];
        }

        // OIDC status
        $userOidcInstalled = $this->appManager->isEnabledForUser('user_oidc');
        $oidcLoginInstalled = $this->appManager->isEnabledForUser('oidc_login');
        $oidcAutoLogin = $this->appConfig->getValueString(self::APP_ID, 'autologin-oidc', '0');

        $oidcProvider = 'none';
        if ($userOidcInstalled) {
            $oidcProvider = 'user_oidc';
        } elseif ($oidcLoginInstalled) {
            $oidcProvider = 'oidc_login';
        }

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
            'oidc' => [
                'enabled' => $oidcAutoLogin === '1',
                'provider' => $oidcProvider,
                'user_oidc' => $userOidcInstalled,
                'oidc_login' => $oidcLoginInstalled,
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
        int $smtp_port = 25,
        string $smtp_ssl = 'none',
        string $auth_type = 'plain'
    ): JSONResponse {
        $imapHost = $imap_host;
        $imapPort = $imap_port;
        $imapSsl = \strtolower($imap_ssl);
        $smtpHost = $smtp_host ?: $imap_host;
        $smtpPort = $smtp_port;
        $smtpSsl = \strtolower($smtp_ssl);
        $authType = \strtolower($auth_type);

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

        $results = [];

        // IMAP check
        $imapResult = $this->checkImap($imapHost, $imapPort, $imapSsl);
        $results['imap'] = $imapResult;

        // OAuth capability check
        if ($imapResult['connected'] && \in_array($authType, ['oauth', 'oauthbearer', 'xoauth2'])) {
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
        $results['smtp'] = $this->checkSmtp($smtpHost, $smtpPort, $smtpSsl);

        // OIDC check
        if (\in_array($authType, ['oauth', 'oauthbearer', 'xoauth2'])) {
            $userOidc = $this->appManager->isEnabledForUser('user_oidc');
            $oidcLogin = $this->appManager->isEnabledForUser('oidc_login');

            $oidcResult = [
                'user_oidc' => $userOidc,
                'oidc_login' => $oidcLogin,
                'any_installed' => $userOidc || $oidcLogin,
            ];

            if ($userOidc) {
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
            if ($accessToken && \is_string($accessToken)) {
                $parts = \explode('.', $accessToken);
                if (\count($parts) === 3) {
                    $b64 = \strtr($parts[1], '-_', '+/');
                    $b64 .= \str_repeat('=', (4 - \strlen($b64) % 4) % 4);
                    $payload = \json_decode(\base64_decode($b64), true);
                    if ($payload) {
                        $oidcResult['token'] = [
                            'email' => $payload['email'] ?? null,
                            'aud' => $payload['aud'] ?? null,
                            'iss' => $payload['iss'] ?? null,
                            'exp' => $payload['exp'] ?? null,
                            'expires_in' => isset($payload['exp']) ? $payload['exp'] - \time() : null,
                        ];
                    }
                }
            }

            $results['oidc'] = $oidcResult;
        }

        return new JSONResponse($results);
    }

    /**
     * Save setup configuration (create or update domain).
     */
    public function saveSetup(
        string $domain = '',
        string $imap_host = '',
        int $imap_port = 143,
        string $imap_ssl = 'none',
        string $smtp_host = '',
        int $smtp_port = 25,
        string $smtp_ssl = 'none',
        bool $smtp_auth = false,
        string $auth_type = 'plain',
        bool $sieve = false
    ): JSONResponse {
        $domain = \trim($domain);
        $imapHost = \trim($imap_host);
        $imapPort = $imap_port;
        $imapSsl = \strtolower($imap_ssl);
        $smtpHost = \trim($smtp_host) ?: $imapHost;
        $smtpPort = $smtp_port;
        $smtpSsl = \strtolower($smtp_ssl);
        $smtpAuth = $smtp_auth;
        $authType = \strtolower($auth_type);

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
        if ($imapPort < 1 || $imapPort > 65535) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid IMAP port'], 400);
        }
        if ($smtpPort < 1 || $smtpPort > 65535) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid SMTP port'], 400);
        }
        // Normalize legacy auth type values
        if ($authType === 'oauthbearer' || $authType === 'xoauth2') {
            $authType = 'oauth';
        }
        if (!\in_array($authType, ['plain', 'oauth'])) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid auth type'], 400);
        }
        if (!\in_array($imapSsl, ['none', 'ssl', 'starttls'])) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid IMAP SSL mode'], 400);
        }
        if (!\in_array($smtpSsl, ['none', 'ssl', 'starttls'])) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid SMTP SSL mode'], 400);
        }

        try {
            // Single-domain mode: remove any previous domain configs
            foreach ($this->domainService->listDomains() as $existing) {
                if ($existing !== $domain) {
                    $this->domainService->deleteDomainConfig($existing);
                }
            }

            $domainConfig = $this->domainService->buildDomainConfig(
                $imapHost,
                $imapPort,
                $imapSsl,
                $smtpHost,
                $smtpPort,
                $smtpSsl,
                $smtpAuth,
                $authType,
                $sieve,
            );
            $this->domainService->writeDomainConfig($domain, $domainConfig);

            // Set app config for OIDC auto-login
            $isOAuth = $authType === 'oauth';
            $this->appConfig->setValueString(self::APP_ID, 'autologin', '1');
            $this->appConfig->setValueString(self::APP_ID, 'autologin-oidc', $isOAuth ? '1' : '0');

            // Ensure store_login_token is set for user_oidc
            if ($isOAuth && $this->appManager->isEnabledForUser('user_oidc')) {
                $this->appConfig->setValueString('user_oidc', 'store_login_token', '1');
            }

            // Set engine config for this auth mode
            try {
                $this->engineHelper->loadApp();
                $oConfig = \X2Mail\Engine\Api::Config();
                $oConfig->Set('login', 'default_domain', $domain);
                if ($isOAuth) {
                    $oConfig->Set('webmail', 'allow_additional_accounts', false);
                    $oConfig->Set('login', 'sign_me_auto', \X2Mail\Engine\Enumerations\SignMeType::Unused);
                    $oConfig->Set('imap', 'show_login_alert', false);
                    $oConfig->Set('defaults', 'autologout', 15);
                }
                $oConfig->Save();

                // Invalidate stale auth: NC session + engine session + stored credentials
                $this->session->remove('x2mail-passphrase');
                \X2Mail\Engine\Api::Actions()->Logout(true);

                if ($isOAuth) {
                    $this->userConfig->deleteKey('x2mail', 'passphrase');
                    $this->userConfig->deleteKey('x2mail', 'email');
                }
            } catch (\Throwable $e) {
                // Non-fatal
            }

            return new JSONResponse([
                'status' => 'success',
                'message' => "Domain '{$domain}' saved",
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('X2Mail saveSetup failed: ' . $e->getMessage());
            return new JSONResponse(['status' => 'error', 'message' => 'Save failed'], 500);
        }
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

    /** @return array<string, mixed> */
    private function checkImap(string $host, int $port, string $ssl): array
    {
        $result = ['connected' => false, 'capabilities' => [], 'error' => ''];

        if ($host === '') {
            $result['error'] = 'No host specified';
            return $result;
        }

        try {
            $prefix = match ($ssl) {
                'ssl' => 'ssl://',
                default => 'tcp://',
            };

            $errno = 0;
            $errstr = '';
            $fp = @\stream_socket_client($prefix . $host . ':' . $port, $errno, $errstr, 10);
            if (!$fp) {
                $result['error'] = 'Connection failed';
                return $result;
            }

            \stream_set_timeout($fp, 10);
            $banner = \fgets($fp, 4096);

            if (!$banner) {
                \fclose($fp);
                $result['error'] = 'No response from server';
                return $result;
            }

            $result['connected'] = true;

            if (\preg_match('/\[CAPABILITY\s+([^\]]+)\]/', $banner, $m)) {
                $result['capabilities'] = \explode(' ', \trim($m[1]));
            }

            if (empty($result['capabilities'])) {
                \fwrite($fp, "A001 CAPABILITY\r\n");
                $capLine = '';
                $tries = 0;
                while ($tries++ < 10) {
                    $line = \fgets($fp, 4096);
                    if ($line === false) {
                        break;
                    }
                    if (\str_starts_with($line, '* CAPABILITY ')) {
                        $capLine = \trim(\substr($line, 13));
                    }
                    if (\str_starts_with($line, 'A001 ')) {
                        break;
                    }
                }
                if ($capLine !== '') {
                    $result['capabilities'] = \explode(' ', $capLine);
                }
            }

            \fwrite($fp, "A002 LOGOUT\r\n");
            \fclose($fp);
        } catch (\Throwable $e) {
            $result['error'] = 'Connection failed';
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function checkSmtp(string $host, int $port, string $ssl): array
    {
        $result = ['connected' => false, 'banner' => '', 'error' => ''];

        if ($host === '') {
            $result['error'] = 'No host specified';
            return $result;
        }

        try {
            $prefix = match ($ssl) {
                'ssl' => 'ssl://',
                default => 'tcp://',
            };

            $errno = 0;
            $errstr = '';
            $fp = @\stream_socket_client($prefix . $host . ':' . $port, $errno, $errstr, 10);
            if (!$fp) {
                $result['error'] = 'Connection failed';
                return $result;
            }

            \stream_set_timeout($fp, 10);
            $banner = \trim(\fgets($fp, 4096) ?: '');
            \fwrite($fp, "QUIT\r\n");
            \fclose($fp);

            $result['connected'] = true;
            $result['banner'] = $banner;
        } catch (\Throwable $e) {
            $result['error'] = 'Connection failed';
        }

        return $result;
    }
}
