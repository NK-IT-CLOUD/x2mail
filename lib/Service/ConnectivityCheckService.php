<?php

declare(strict_types=1);

namespace OCA\X2Mail\Service;

use OCA\X2Mail\Util\EngineHelper;

class ConnectivityCheckService
{
    /**
     * Returned by the auth checks instead of sending the token in the clear.
     */
    private const NO_TLS_RESULT = [
        'authenticated' => false,
        'tls_required' => true,
        'error' => 'No TLS configured, login not tested (the OIDC token is only sent over SSL/TLS or STARTTLS)',
    ];

    public function __construct(
        private ?EngineHelper $engineHelper = null,
    ) {
    }

    /**
     * Build the OAUTHBEARER SASL initial client response (base64-encoded).
     * Format: n,a=<user>,\x01auth=Bearer <token>\x01\x01
     */
    public function buildOauthbearerSasl(string $user, string $token): string
    {
        return \base64_encode("n,a={$user},\x01auth=Bearer {$token}\x01\x01");
    }

    /**
     * Same rule as the engine (NetClient::assertEncryptedForBearerAuth, RFC 7628 §5):
     * the bearer token never crosses the wire unencrypted, loopback excepted.
     */
    private function bearerNeedsTls(string $host, string $mode): bool
    {
        return !\in_array($mode, ['ssl', 'starttls', 'tls'], true)
            && !\in_array($host, ['127.0.0.1', '::1', 'localhost'], true);
    }

    /**
     * Attempt a real IMAP AUTHENTICATE OAUTHBEARER login.
     *
     * @return array{authenticated: bool, tls_required?: bool, error?: string}
     */
    public function authCheckImap(
        string $host,
        int $port,
        string $ssl,
        string $user,
        string $token
    ): array {
        if ($host === '') {
            return ['authenticated' => false, 'error' => 'No host specified'];
        }
        $mode = \strtolower($ssl);
        if ($this->bearerNeedsTls($host, $mode)) {
            return self::NO_TLS_RESULT;
        }
        $tlsOptions = $this->buildTlsContextOptions($host, false);
        try {
            return $this->runImapAuthCheck($host, $port, $mode, $tlsOptions, $user, $token);
        } catch (\Throwable $e) {
            return ['authenticated' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Attempt a real SMTP AUTH OAUTHBEARER login.
     *
     * @return array{authenticated: bool, tls_required?: bool, error?: string}
     */
    public function authCheckSmtp(
        string $host,
        int $port,
        string $ssl,
        string $user,
        string $token
    ): array {
        if ($host === '') {
            return ['authenticated' => false, 'error' => 'No host specified'];
        }
        $mode = \strtolower($ssl);
        if ($this->bearerNeedsTls($host, $mode)) {
            return self::NO_TLS_RESULT;
        }
        $tlsOptions = $this->buildTlsContextOptions($host, false);
        try {
            return $this->runSmtpAuthCheck($host, $port, $mode, $tlsOptions, $user, $token);
        } catch (\Throwable $e) {
            return ['authenticated' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Attempt a real ManageSieve AUTHENTICATE OAUTHBEARER login.
     *
     * @return array{authenticated: bool, tls_required?: bool, error?: string}
     */
    public function authCheckSieve(
        string $host,
        int $port,
        string $ssl,
        string $user,
        string $token
    ): array {
        if ($host === '') {
            return ['authenticated' => false, 'error' => 'No host specified'];
        }
        $mode = \strtolower($ssl);
        if ($this->bearerNeedsTls($host, $mode)) {
            return self::NO_TLS_RESULT;
        }
        $tlsOptions = $this->buildTlsContextOptions($host, false);
        try {
            return $this->runSieveAuthCheck($host, $port, $mode, $tlsOptions, $user, $token);
        } catch (\Throwable $e) {
            return ['authenticated' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{connected: bool, capabilities: list<string>, error: string,
     *               starttls_supported: bool, tls_active: bool, tls_verified: bool, tls_warning: string}
     */
    public function checkImap(string $host, int $port, string $ssl): array
    {
        if ($host === '') {
            return [
                'connected' => false,
                'capabilities' => [],
                'error' => 'No host specified',
                'starttls_supported' => false,
                'tls_active' => false,
                'tls_verified' => false,
                'tls_warning' => '',
            ];
        }

        $mode = \strtolower($ssl);
        $strictResult = $this->runImapCheck($host, $port, $mode, $this->buildTlsContextOptions($host, false));
        if ($strictResult['connected'] || $mode === 'none') {
            return $strictResult;
        }

        $relaxedResult = $this->runImapCheck($host, $port, $mode, $this->buildTlsContextOptions($host, true));
        if ($relaxedResult['connected']) {
            $relaxedResult['tls_verified'] = false;
            $relaxedResult['tls_warning'] = 'TLS verification failed with current X2Mail SSL settings;'
                . ' diagnostics retried with relaxed certificate checks.'
                . ($strictResult['error'] !== '' ? ' Strict check: ' . $strictResult['error'] : '');
            $relaxedResult['error'] = '';
            return $relaxedResult;
        }

        return $strictResult;
    }

    /**
     * @return array{connected: bool, banner: string, capabilities: list<string>,
     *               auth_methods: list<string>, error: string, starttls_supported: bool,
     *               tls_active: bool, tls_verified: bool, tls_warning: string}
     */
    public function checkSmtp(string $host, int $port, string $ssl): array
    {
        if ($host === '') {
            return [
                'connected' => false,
                'banner' => '',
                'capabilities' => [],
                'auth_methods' => [],
                'error' => 'No host specified',
                'starttls_supported' => false,
                'tls_active' => false,
                'tls_verified' => false,
                'tls_warning' => '',
            ];
        }

        $mode = \strtolower($ssl);
        $strictResult = $this->runSmtpCheck($host, $port, $mode, $this->buildTlsContextOptions($host, false));
        if ($strictResult['connected'] || $mode === 'none') {
            return $strictResult;
        }

        $relaxedResult = $this->runSmtpCheck($host, $port, $mode, $this->buildTlsContextOptions($host, true));
        if ($relaxedResult['connected']) {
            $relaxedResult['tls_verified'] = false;
            $relaxedResult['tls_warning'] = 'TLS verification failed with current X2Mail SSL settings;'
                . ' diagnostics retried with relaxed certificate checks.'
                . ($strictResult['error'] !== '' ? ' Strict check: ' . $strictResult['error'] : '');
            $relaxedResult['error'] = '';
            return $relaxedResult;
        }

        return $strictResult;
    }

    /**
     * @param array<string, mixed> $tlsOptions
     * @return resource
     */
    private function openSocket(string $host, int $port, bool $implicitTls, array $tlsOptions)
    {
        $errno = 0;
        $errstr = '';
        $context = \stream_context_create(['ssl' => $tlsOptions]);

        $scheme = $implicitTls ? 'ssl://' : 'tcp://';
        [$fp, $warning] = $this->captureWarnings(
            static fn () => \stream_socket_client(
                $scheme . $host . ':' . $port,
                $errno,
                $errstr,
                10,
                \STREAM_CLIENT_CONNECT,
                $context
            )
        );

        if (!\is_resource($fp)) {
            throw new \RuntimeException($warning ?? "Connection failed (errno={$errno})");
        }

        \stream_set_timeout($fp, 10);

        return $fp;
    }

    /**
     * @param resource $fp
     */
    private function readLine($fp): ?string
    {
        $line = \fgets($fp, 4096);
        return $line === false ? null : $line;
    }

    /**
     * @return list<string>
     */
    private function extractImapCapabilities(string $banner): array
    {
        if (\preg_match('/\[CAPABILITY\s+([^\]]+)\]/', $banner, $matches) !== 1) {
            return [];
        }

        return \array_values(\array_filter(\explode(' ', \trim($matches[1]))));
    }

    /**
     * @param resource $fp
     * @return list<string>
     */
    private function fetchImapCapabilities($fp, string $tag): array
    {
        \fwrite($fp, $tag . " CAPABILITY\r\n");
        $response = $this->readImapTaggedResponse($fp, $tag);
        foreach ($response as $line) {
            if (\str_starts_with($line, '* CAPABILITY ')) {
                return \array_values(\array_filter(\explode(' ', \trim(\substr($line, 13)))));
            }
        }

        return [];
    }

    /**
     * @param resource $fp
     * @return list<string>
     */
    private function readImapTaggedResponse($fp, string $tag): array
    {
        $lines = [];
        $tries = 0;

        while ($tries++ < 20) {
            $line = $this->readLine($fp);
            if ($line === null) {
                break;
            }

            $lines[] = $line;
            if (\str_starts_with($line, $tag . ' ')) {
                break;
            }
        }

        return $lines;
    }

    /**
     * @param list<string> $response
     */
    private function imapResponseIsOk(array $response, string $tag): bool
    {
        foreach ($response as $line) {
            if (\preg_match('/^' . \preg_quote($tag, '/') . '\s+OK\b/i', $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param resource $fp
     * @return list<string>
     */
    private function readSmtpResponse($fp): array
    {
        $lines = [];
        $tries = 0;

        while ($tries++ < 20) {
            $line = $this->readLine($fp);
            if ($line === null) {
                break;
            }

            $lines[] = $line;
            if (\preg_match('/^\d{3}\s/', $line) === 1) {
                break;
            }
        }

        return $lines;
    }

    /**
     * @param list<string> $response
     */
    private function smtpResponseCode(array $response): ?int
    {
        if ($response === []) {
            return null;
        }

        if (\preg_match('/^(\d{3})[-\s]/', $response[0], $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * @param resource $fp
     * @return array{capabilities: list<string>, auth_methods: list<string>}
     */
    private function smtpEhlo($fp): array
    {
        \fwrite($fp, "EHLO x2mail.local\r\n");
        $response = $this->readSmtpResponse($fp);
        if ($this->smtpResponseCode($response) !== 250) {
            throw new \RuntimeException('EHLO rejected by server');
        }

        $capabilities = [];
        $authMethods = [];
        foreach ($response as $line) {
            if (\preg_match('/^250[-\s](.+)$/', \trim($line), $matches) === 1) {
                $capability = \trim($matches[1]);
                if ($capability !== '') {
                    $parts = \preg_split('/\s+/', $capability);
                    if ($parts !== false) {
                        $name = \strtoupper($parts[0]);
                        $capabilities[] = $name;
                        if ($name === 'AUTH' && \count($parts) > 1) {
                            foreach (\array_slice($parts, 1) as $method) {
                                $authMethods[] = \strtoupper($method);
                            }
                        }
                    }
                }
            }
        }

        return [
            'capabilities' => \array_values(\array_unique($capabilities)),
            'auth_methods' => \array_values(\array_unique($authMethods)),
        ];
    }

    /**
     * @param array<string, mixed> $tlsOptions
     * @return array{connected: bool, capabilities: list<string>, error: string,
     *               starttls_supported: bool, tls_active: bool, tls_verified: bool, tls_warning: string}
     */
    private function runImapCheck(string $host, int $port, string $mode, array $tlsOptions): array
    {
        $result = [
            'connected' => false,
            'capabilities' => [],
            'error' => '',
            'starttls_supported' => false,
            'tls_active' => false,
            'tls_verified' => false,
            'tls_warning' => '',
        ];

        try {
            $fp = $this->openSocket($host, $port, $mode === 'ssl', $tlsOptions);
            $banner = $this->readLine($fp);
            if ($banner === null) {
                \fclose($fp);
                $result['error'] = 'No response from server';
                return $result;
            }

            $capabilities = $this->extractImapCapabilities($banner);
            if ($capabilities === []) {
                $capabilities = $this->fetchImapCapabilities($fp, 'A001');
            }

            $starttlsSupported = \in_array('STARTTLS', $capabilities, true);
            $result['starttls_supported'] = $starttlsSupported;

            if ($mode === 'starttls' || $mode === 'tls') {
                if (!$starttlsSupported) {
                    \fclose($fp);
                    $result['error'] = 'STARTTLS not advertised by server';
                    return $result;
                }

                \fwrite($fp, "A002 STARTTLS\r\n");
                $startTlsResponse = $this->readImapTaggedResponse($fp, 'A002');
                if (!$this->imapResponseIsOk($startTlsResponse, 'A002')) {
                    \fclose($fp);
                    $result['error'] = 'STARTTLS rejected by server';
                    return $result;
                }

                $this->enableTls($fp);
                $result['tls_active'] = true;
                $result['tls_verified'] = $this->isTlsVerificationEnabled($tlsOptions);
                $capabilities = $this->fetchImapCapabilities($fp, 'A003');
            } elseif ($mode === 'ssl') {
                $result['tls_active'] = true;
                $result['tls_verified'] = $this->isTlsVerificationEnabled($tlsOptions);
            }

            $result['connected'] = true;
            $result['capabilities'] = $capabilities;

            \fwrite($fp, "A004 LOGOUT\r\n");
            \fclose($fp);
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $tlsOptions
     * @return array{connected: bool, banner: string, capabilities: list<string>,
     *               auth_methods: list<string>, error: string, starttls_supported: bool,
     *               tls_active: bool, tls_verified: bool, tls_warning: string}
     */
    private function runSmtpCheck(string $host, int $port, string $mode, array $tlsOptions): array
    {
        $result = [
            'connected' => false,
            'banner' => '',
            'capabilities' => [],
            'auth_methods' => [],
            'error' => '',
            'starttls_supported' => false,
            'tls_active' => false,
            'tls_verified' => false,
            'tls_warning' => '',
        ];

        try {
            $fp = $this->openSocket($host, $port, $mode === 'ssl', $tlsOptions);
            $bannerLines = $this->readSmtpResponse($fp);
            $banner = \trim($bannerLines[0] ?? '');
            if ($banner === '') {
                \fclose($fp);
                $result['error'] = 'No response from server';
                return $result;
            }

            $result['banner'] = $banner;
            $ehlo = $this->smtpEhlo($fp);
            $result['capabilities'] = $ehlo['capabilities'];
            $result['auth_methods'] = $ehlo['auth_methods'];
            $result['starttls_supported'] = \in_array('STARTTLS', $ehlo['capabilities'], true);

            if ($mode === 'starttls' || $mode === 'tls') {
                if (!$result['starttls_supported']) {
                    \fclose($fp);
                    $result['error'] = 'STARTTLS not advertised by server';
                    return $result;
                }

                \fwrite($fp, "STARTTLS\r\n");
                $startTlsResponse = $this->readSmtpResponse($fp);
                if ($this->smtpResponseCode($startTlsResponse) !== 220) {
                    \fclose($fp);
                    $result['error'] = 'STARTTLS rejected by server';
                    return $result;
                }

                $this->enableTls($fp);
                $result['tls_active'] = true;
                $result['tls_verified'] = $this->isTlsVerificationEnabled($tlsOptions);
                $ehlo = $this->smtpEhlo($fp);
                $result['capabilities'] = $ehlo['capabilities'];
                $result['auth_methods'] = $ehlo['auth_methods'];
            } elseif ($mode === 'ssl') {
                $result['tls_active'] = true;
                $result['tls_verified'] = $this->isTlsVerificationEnabled($tlsOptions);
            }

            $result['connected'] = true;
            \fwrite($fp, "QUIT\r\n");
            \fclose($fp);
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * @param resource $fp
     */
    private function enableTls($fp): void
    {
        // TLS 1.2+ like the engine (NetClient::EnableCrypto); TLS_CLIENT would also allow 1.0/1.1
        $method = \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        [$enabled, $warning] = $this->captureWarnings(
            static fn () => \stream_socket_enable_crypto($fp, true, $method)
        );

        if ($enabled !== true) {
            throw new \RuntimeException($warning ?? 'STARTTLS negotiation failed');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTlsContextOptions(string $host, bool $relaxed): array
    {
        $defaults = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'SNI_enabled' => true,
            'disable_compression' => true,
            'security_level' => 1,
        ];

        try {
            if ($this->engineHelper !== null) {
                $this->engineHelper->loadApp();
            }

            if (\class_exists('\\X2Mail\\Engine\\Api')) {
                $oConfig = \X2Mail\Engine\Api::Config();
                $defaults['verify_peer'] = (bool) $oConfig->Get('ssl', 'verify_certificate', true);
                $defaults['verify_peer_name'] = (bool) $oConfig->Get('ssl', 'verify_certificate', true);
                $defaults['allow_self_signed'] = (bool) $oConfig->Get('ssl', 'allow_self_signed', false);
                $defaults['disable_compression'] = (bool) $oConfig->Get('ssl', 'disable_compression', true);
                $defaults['security_level'] = (int) $oConfig->Get('ssl', 'security_level', 1);

                $cafile = \trim((string) $oConfig->Get('ssl', 'cafile', ''));
                if ($cafile !== '') {
                    $defaults['cafile'] = $cafile;
                }

                $capath = \trim((string) $oConfig->Get('ssl', 'capath', ''));
                if ($capath !== '') {
                    $defaults['capath'] = $capath;
                }

                $localCert = \trim((string) $oConfig->Get('ssl', 'local_cert', ''));
                if ($localCert !== '') {
                    $defaults['local_cert'] = $localCert;
                }
            }
        } catch (\Throwable $e) {
            // Fall back to built-in defaults when engine bootstrap is unavailable.
        }

        if ($relaxed) {
            $defaults['verify_peer'] = false;
            $defaults['verify_peer_name'] = false;
            $defaults['allow_self_signed'] = true;
            unset($defaults['cafile'], $defaults['capath']);
            return $defaults;
        }

        if (!empty($defaults['verify_peer']) && !empty($defaults['verify_peer_name'])) {
            $defaults['peer_name'] = $host;
        } elseif (empty($defaults['verify_peer'])) {
            $defaults['allow_self_signed'] = true;
        }

        return $defaults;
    }

    /**
     * @param array<string, mixed> $tlsOptions
     */
    private function isTlsVerificationEnabled(array $tlsOptions): bool
    {
        return ($tlsOptions['verify_peer'] ?? false) === true
            && ($tlsOptions['verify_peer_name'] ?? false) === true;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return array{0: T, 1: ?string}
     */
    private function captureWarnings(callable $callback): array
    {
        $warning = null;

        \set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        });

        try {
            $result = $callback();
        } finally {
            \restore_error_handler();
        }

        return [$result, $warning];
    }

    /**
     * @param array<string, mixed> $tlsOptions
     * @return array{authenticated: bool, error?: string}
     */
    private function runImapAuthCheck(
        string $host,
        int $port,
        string $mode,
        array $tlsOptions,
        string $user,
        string $token
    ): array {
        $fp = $this->openSocket($host, $port, $mode === 'ssl', $tlsOptions);

        try {
            // Read greeting
            $banner = $this->readLine($fp);
            if ($banner === null) {
                return ['authenticated' => false, 'error' => 'No response from server'];
            }

            // Fetch capabilities if not in banner
            $capabilities = $this->extractImapCapabilities($banner);
            if ($capabilities === []) {
                $capabilities = $this->fetchImapCapabilities($fp, 'C001');
            }

            // STARTTLS upgrade if requested
            if ($mode === 'starttls' || $mode === 'tls') {
                if (!\in_array('STARTTLS', $capabilities, true)) {
                    return ['authenticated' => false, 'error' => 'STARTTLS not advertised by server'];
                }
                \fwrite($fp, "C002 STARTTLS\r\n");
                $resp = $this->readImapTaggedResponse($fp, 'C002');
                if (!$this->imapResponseIsOk($resp, 'C002')) {
                    return ['authenticated' => false, 'error' => 'STARTTLS rejected by server'];
                }
                $this->enableTls($fp);
                $capabilities = $this->fetchImapCapabilities($fp, 'C003');
            }

            // Authenticate
            $sasl = $this->buildOauthbearerSasl($user, $token);
            \fwrite($fp, "A1 AUTHENTICATE OAUTHBEARER {$sasl}\r\n");
            $authLines = $this->readImapTaggedResponse($fp, 'A1');

            // If server sent a continuation (+), it is signalling an error challenge.
            // Per RFC 7628/4959 respond with the base64 dummy "AQ==" to get the tagged NO.
            foreach ($authLines as $line) {
                if (\str_starts_with(\ltrim($line), '+ ') || \rtrim($line) === '+') {
                    \fwrite($fp, "AQ==\r\n");
                    // Read more lines until the tagged response
                    $extra = $this->readImapTaggedResponse($fp, 'A1');
                    $authLines = \array_merge($authLines, $extra);
                    break;
                }
            }

            $authOk = $this->imapResponseIsOk($authLines, 'A1');
            if (!$authOk) {
                $errorLine = '';
                foreach ($authLines as $line) {
                    if (\str_starts_with($line, 'A1 ')) {
                        $errorLine = \trim($line);
                        break;
                    }
                }
                \fwrite($fp, "A2 LOGOUT\r\n");
                \fclose($fp);
                return ['authenticated' => false, 'error' => $errorLine ?: 'Authentication rejected'];
            }

            // Verify inbox access
            \fwrite($fp, "A2 SELECT INBOX\r\n");
            $this->readImapTaggedResponse($fp, 'A2');

            \fwrite($fp, "A3 LOGOUT\r\n");
            \fclose($fp);
            return ['authenticated' => true];
        } catch (\Throwable $e) {
            // Best-effort close; ignore close errors
            try {
                \fclose($fp);
            } catch (\Throwable $ignored) {
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $tlsOptions
     * @return array{authenticated: bool, error?: string}
     */
    private function runSmtpAuthCheck(
        string $host,
        int $port,
        string $mode,
        array $tlsOptions,
        string $user,
        string $token
    ): array {
        $fp = $this->openSocket($host, $port, $mode === 'ssl', $tlsOptions);

        try {
            // Read banner
            $bannerLines = $this->readSmtpResponse($fp);
            if ($this->smtpResponseCode($bannerLines) !== 220) {
                return ['authenticated' => false, 'error' => 'Unexpected banner: ' . \trim($bannerLines[0] ?? '')];
            }

            $ehlo = $this->smtpEhlo($fp);

            // STARTTLS upgrade if requested
            if ($mode === 'starttls' || $mode === 'tls') {
                if (!\in_array('STARTTLS', $ehlo['capabilities'], true)) {
                    return ['authenticated' => false, 'error' => 'STARTTLS not advertised by server'];
                }
                \fwrite($fp, "STARTTLS\r\n");
                $tlsResp = $this->readSmtpResponse($fp);
                if ($this->smtpResponseCode($tlsResp) !== 220) {
                    return ['authenticated' => false, 'error' => 'STARTTLS rejected by server'];
                }
                $this->enableTls($fp);
                $ehlo = $this->smtpEhlo($fp);
            }

            // Authenticate
            $sasl = $this->buildOauthbearerSasl($user, $token);
            \fwrite($fp, "AUTH OAUTHBEARER {$sasl}\r\n");
            $authResp = $this->readSmtpResponse($fp);
            $code = $this->smtpResponseCode($authResp);

            // Some servers send 334 (continuation) with a base64-encoded error on failure.
            // Per RFC 4954 send "*" to cancel the exchange and read the final error code.
            if ($code === 334) {
                \fwrite($fp, "*\r\n");
                $authResp = $this->readSmtpResponse($fp);
                $code = $this->smtpResponseCode($authResp);
            }

            \fwrite($fp, "QUIT\r\n");
            \fclose($fp);

            if ($code === 235) {
                return ['authenticated' => true];
            }

            return [
                'authenticated' => false,
                'error' => \trim($authResp[0] ?? "Auth failed (code {$code})"),
            ];
        } catch (\Throwable $e) {
            try {
                \fclose($fp);
            } catch (\Throwable $ignored) {
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $tlsOptions
     * @return array{authenticated: bool, error?: string}
     */
    private function runSieveAuthCheck(
        string $host,
        int $port,
        string $mode,
        array $tlsOptions,
        string $user,
        string $token
    ): array {
        $fp = $this->openSocket($host, $port, $mode === 'ssl', $tlsOptions);

        try {
            // Read Sieve greeting (multi-line capability block ending with OK)
            $greeting = $this->readSieveGreeting($fp);

            // STARTTLS upgrade if requested; never fall back to plaintext (RFC 5804 §2.2)
            if ($mode === 'starttls' || $mode === 'tls') {
                if (!\in_array('STARTTLS', $greeting['capabilities'], true)) {
                    return ['authenticated' => false, 'error' => 'STARTTLS not advertised by server'];
                }
                \fwrite($fp, "STARTTLS\r\n");
                $tlsLine = $this->readLine($fp);
                if ($tlsLine === null || !\str_starts_with(\strtoupper(\trim($tlsLine)), 'OK')) {
                    return ['authenticated' => false, 'error' => 'Sieve STARTTLS rejected'];
                }
                $this->enableTls($fp);
                // Re-read greeting after TLS upgrade
                $greeting = $this->readSieveGreeting($fp);
            }

            // Authenticate
            $sasl = $this->buildOauthbearerSasl($user, $token);
            \fwrite($fp, "AUTHENTICATE \"OAUTHBEARER\" \"{$sasl}\"\r\n");
            $authLine = $this->readLine($fp);

            \fwrite($fp, "LOGOUT\r\n");
            \fclose($fp);

            if ($authLine !== null && \str_starts_with(\strtoupper(\trim($authLine)), 'OK')) {
                return ['authenticated' => true];
            }

            return [
                'authenticated' => false,
                'error' => \trim($authLine ?? 'No response to AUTHENTICATE'),
            ];
        } catch (\Throwable $e) {
            try {
                \fclose($fp);
            } catch (\Throwable $ignored) {
            }
            throw $e;
        }
    }

    /**
     * Read the ManageSieve capability greeting block (lines until "OK" or "NO").
     *
     * @param resource $fp
     * @return array{capabilities: list<string>, sasl_methods: list<string>}
     */
    private function readSieveGreeting($fp): array
    {
        $capabilities = [];
        $saslMethods = [];
        $tries = 0;

        while ($tries++ < 30) {
            $line = $this->readLine($fp);
            if ($line === null) {
                break;
            }
            $upper = \strtoupper(\trim($line));
            if (\str_starts_with($upper, 'OK') || \str_starts_with($upper, 'NO')) {
                break;
            }
            // Lines like: "STARTTLS" or "SASL" "PLAIN LOGIN XOAUTH2 OAUTHBEARER"
            if (\preg_match('/^"?([A-Z0-9.\-]+)"?(?:\s+(.*))?$/i', \trim($line), $m) === 1) {
                $cap = \strtoupper($m[1]);
                $capabilities[] = $cap;
                if ($cap === 'SASL' && isset($m[2]) && $m[2] !== '') {
                    $saslMethods = \preg_split('/\s+/', \strtoupper(\trim($m[2], " \t\"")));
                }
            }
        }

        return ['capabilities' => $capabilities, 'sasl_methods' => $saslMethods ?: []];
    }

    /**
     * Probe ManageSieve reachability + advertised SASL mechanisms (no login).
     *
     * @return array{connected: bool, sasl_methods: list<string>, oauth_supported: bool,
     *               starttls_supported: bool, error: string, tls_warning: string}
     */
    public function checkSieve(string $host, int $port, string $ssl): array
    {
        if ($host === '') {
            return [
                'connected' => false,
                'sasl_methods' => [],
                'oauth_supported' => false,
                'starttls_supported' => false,
                'error' => 'No host specified',
                'tls_warning' => '',
            ];
        }

        $mode = \strtolower($ssl);
        $strict = $this->runSieveCheck($host, $port, $mode, $this->buildTlsContextOptions($host, false));
        if ($strict['connected'] || $mode === 'none') {
            return $strict;
        }

        $relaxed = $this->runSieveCheck($host, $port, $mode, $this->buildTlsContextOptions($host, true));
        if ($relaxed['connected']) {
            $relaxed['tls_warning'] = 'TLS verification failed with current X2Mail SSL settings;'
                . ' diagnostics retried with relaxed certificate checks.'
                . ($strict['error'] !== '' ? ' Strict check: ' . $strict['error'] : '');
            $relaxed['error'] = '';
            return $relaxed;
        }

        return $strict;
    }

    /**
     * @param array<string, mixed> $tlsOptions
     * @return array{connected: bool, sasl_methods: list<string>, oauth_supported: bool,
     *               starttls_supported: bool, error: string, tls_warning: string}
     */
    private function runSieveCheck(string $host, int $port, string $mode, array $tlsOptions): array
    {
        $result = [
            'connected' => false,
            'sasl_methods' => [],
            'oauth_supported' => false,
            'starttls_supported' => false,
            'error' => '',
            'tls_warning' => '',
        ];

        try {
            $fp = $this->openSocket($host, $port, $mode === 'ssl', $tlsOptions);
            $greeting = $this->readSieveGreeting($fp);
            $result['starttls_supported'] = \in_array('STARTTLS', $greeting['capabilities'], true);

            if ($mode === 'starttls' || $mode === 'tls') {
                if (!$result['starttls_supported']) {
                    \fclose($fp);
                    $result['error'] = 'STARTTLS not advertised by server';
                    return $result;
                }
                \fwrite($fp, "STARTTLS\r\n");
                $tlsLine = $this->readLine($fp);
                if ($tlsLine === null || !\str_starts_with(\strtoupper(\trim($tlsLine)), 'OK')) {
                    \fclose($fp);
                    $result['error'] = 'STARTTLS rejected by server';
                    return $result;
                }
                $this->enableTls($fp);
                $greeting = $this->readSieveGreeting($fp);
            }

            $result['sasl_methods'] = $greeting['sasl_methods'];
            $result['oauth_supported'] = \in_array('OAUTHBEARER', $greeting['sasl_methods'], true)
                || \in_array('XOAUTH2', $greeting['sasl_methods'], true);
            $result['connected'] = true;
            \fwrite($fp, "LOGOUT\r\n");
            \fclose($fp);
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }
}
