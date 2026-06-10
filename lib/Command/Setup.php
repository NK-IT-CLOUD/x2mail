<?php

declare(strict_types=1);

namespace OCA\X2Mail\Command;

use OCA\X2Mail\Service\ConnectivityCheckService;
use OCA\X2Mail\Service\DomainConfigService;
use OCA\X2Mail\Util\EngineHelper;
use OCA\X2Mail\Util\SetupResolvers;
use Symfony\Component\Console\Command\Command;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Setup extends Command
{
    use SetupResolvers;

    private const APP_ID = 'x2mail';
    private const OIDC_PROVIDER_KEY = 'oidc-provider';

    public function __construct(
        private IAppConfig $appConfig,
        private DomainConfigService $domainService,
        private IAppManager $appManager,
        private EngineHelper $engineHelper,
        private ConnectivityCheckService $connectivityCheckService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('x2mail:setup')
            ->setDescription('Configure X2Mail mail server connection and authentication')
            ->addOption('imap-host', null, InputOption::VALUE_REQUIRED, 'IMAP server hostname')
            ->addOption('imap-port', null, InputOption::VALUE_REQUIRED, 'IMAP server port', '143')
            ->addOption(
                'imap-ssl',
                null,
                InputOption::VALUE_REQUIRED,
                'IMAP SSL mode (none, ssl, tls/starttls)',
                'none'
            )
            ->addOption('smtp-host', null, InputOption::VALUE_REQUIRED, 'SMTP server hostname (defaults to imap-host)')
            ->addOption('smtp-port', null, InputOption::VALUE_REQUIRED, 'SMTP submission port', '587')
            ->addOption(
                'smtp-ssl',
                null,
                InputOption::VALUE_REQUIRED,
                'SMTP SSL mode (none, ssl, tls/starttls)',
                'none'
            )
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Mail domain (e.g. example.com)')
            ->addOption(
                'oidc-provider',
                null,
                InputOption::VALUE_REQUIRED,
                'OIDC provider app (user_oidc)',
                'user_oidc'
            )
            ->addOption(
                'oidc-audience',
                null,
                InputOption::VALUE_REQUIRED,
                'Optional: target OIDC client/audience for the mail server. '
                . 'When set, x2mail exchanges the login token for one scoped to this audience '
                . '(requires IdP token-exchange support).'
            )
            ->addOption(
                'oidc-scopes',
                null,
                InputOption::VALUE_REQUIRED,
                'Optional: space-separated extra scopes requested during token exchange '
                . '(only if your IdP requires them).'
            )
            ->addOption('sieve', null, InputOption::VALUE_NONE, 'Enable Sieve filtering support')
            ->addOption(
                'sieve-host',
                null,
                InputOption::VALUE_REQUIRED,
                'Sieve server hostname (defaults to imap-host)'
            )
            ->addOption('sieve-port', null, InputOption::VALUE_REQUIRED, 'Sieve server port', '4190')
            ->addOption(
                'sieve-ssl',
                null,
                InputOption::VALUE_REQUIRED,
                'Sieve SSL mode (none, ssl, tls/starttls)',
                'none'
            )
            ->addOption('skip-checks', null, InputOption::VALUE_NONE, 'Skip connectivity and capability checks')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $imapHost = $input->getOption('imap-host');
        $domain = $input->getOption('domain');

        if (!$imapHost) {
            $output->writeln('<error>--imap-host is required</error>');
            return 1;
        }
        if (!$domain) {
            $output->writeln('<error>--domain is required</error>');
            return 1;
        }

        $imapPort = (int) $input->getOption('imap-port');
        $imapSsl = $this->normalizeSslMode((string) $input->getOption('imap-ssl'));
        $smtpHost = $input->getOption('smtp-host') ?: $imapHost;
        $smtpPort = (int) $input->getOption('smtp-port');
        $smtpSsl = $this->normalizeSslMode((string) $input->getOption('smtp-ssl'));
        $sieveHost = $input->getOption('sieve-host') ?: $imapHost;
        $sievePort = (int) $input->getOption('sieve-port');
        $sieveSsl = $this->normalizeSslMode((string) $input->getOption('sieve-ssl'));
        $requestedOidcProvider = $this->normalizeOidcProvider((string) $input->getOption('oidc-provider'));
        $sieve = $input->getOption('sieve');
        $skipChecks = $input->getOption('skip-checks');

        if ($requestedOidcProvider === null) {
            $output->writeln('<error>Invalid --oidc-provider. Must be: user_oidc</error>');
            return 1;
        }
        foreach (['imap-ssl' => $imapSsl, 'smtp-ssl' => $smtpSsl, 'sieve-ssl' => $sieveSsl] as $name => $val) {
            if (!\in_array($val, ['none', 'ssl', 'starttls'], true)) {
                $output->writeln("<error>Invalid --{$name}. Must be: none, ssl, tls/starttls</error>");
                return 1;
            }
        }
        if ($sievePort < 1 || $sievePort > 65535) {
            $output->writeln('<error>Invalid --sieve-port. Must be between 1 and 65535.</error>');
            return 1;
        }

        $errors = 0;
        $userOidcInstalled = $this->appManager->isEnabledForUser('user_oidc');
        $oidcProvider = $userOidcInstalled ? 'user_oidc' : null;

        // ═══════════════════════════════════════════
        // PREFLIGHT CHECKS
        // ═══════════════════════════════════════════
        $output->writeln('<info>Preflight Checks</info>');
        $output->writeln('');

        // 1. IMAP
        if (!$skipChecks) {
            $imapResult = $this->connectivityCheckService->checkImap($imapHost, $imapPort, $imapSsl);
            if ($imapResult['connected']) {
                $authMethods = [];
                $hasOAuth = false;
                foreach ($imapResult['capabilities'] as $cap) {
                    if (\str_starts_with($cap, 'AUTH=')) {
                        $method = \substr($cap, 5);
                        $authMethods[] = $method;
                        if ($method === 'OAUTHBEARER' || $method === 'XOAUTH2') {
                            $hasOAuth = true;
                        }
                    }
                }
                $authStr = $authMethods ? \implode(', ', $authMethods) : 'no AUTH capability advertised';
                $output->writeln("  <info>✓ IMAP  {$imapHost}:{$imapPort} ({$authStr})</info>");

                if (!$hasOAuth) {
                    $output->writeln(
                        '  <error>✗ IMAP server does not support OAUTHBEARER/XOAUTH2</error>'
                    );
                    $output->writeln(
                        '    Dovecot: https://doc.dovecot.org/configuration_manual/authentication/oauth2/'
                    );
                    $errors++;
                }
                if ($imapResult['tls_warning'] !== '') {
                    $output->writeln('  <comment>  ↳ ' . $imapResult['tls_warning'] . '</comment>');
                }
                if (\in_array('STARTTLS', $imapResult['capabilities']) && $imapSsl === 'none') {
                    $output->writeln('  <comment>  ↳ STARTTLS available — consider --imap-ssl tls</comment>');
                }
            } else {
                $output->writeln("  <error>✗ IMAP  {$imapHost}:{$imapPort} — {$imapResult['error']}</error>");
                $errors++;
            }

            // 2. SMTP
            $smtpResult = $this->connectivityCheckService->checkSmtp($smtpHost, $smtpPort, $smtpSsl);
            if ($smtpResult['connected']) {
                $smtpAuthStr = $smtpResult['auth_methods']
                    ? \implode(', ', $smtpResult['auth_methods'])
                    : 'no AUTH advertised';
                $output->writeln("  <info>✓ SMTP  {$smtpHost}:{$smtpPort} ({$smtpAuthStr})</info>");
                $smtpHasOAuth = \in_array('OAUTHBEARER', $smtpResult['auth_methods'], true)
                    || \in_array('XOAUTH2', $smtpResult['auth_methods'], true);
                if (!$smtpHasOAuth) {
                    $output->writeln(
                        '  <error>✗ SMTP server does not support OAUTHBEARER/XOAUTH2'
                        . ' for authenticated sending</error>'
                    );
                    $errors++;
                }
                if ($smtpResult['starttls_supported'] && $smtpSsl === 'none') {
                    $output->writeln('  <comment>  ↳ STARTTLS available — consider --smtp-ssl tls</comment>');
                }
                if ($smtpResult['tls_warning'] !== '') {
                    $output->writeln('  <comment>  ↳ ' . $smtpResult['tls_warning'] . '</comment>');
                }
            } else {
                $output->writeln("  <error>✗ SMTP  {$smtpHost}:{$smtpPort} — {$smtpResult['error']}</error>");
                $errors++;
            }

            // 3. Sieve (only when filtering is enabled)
            if ($sieve) {
                $sieveResult = $this->connectivityCheckService->checkSieve($sieveHost, $sievePort, $sieveSsl);
                if ($sieveResult['connected']) {
                    $sieveAuthStr = $sieveResult['sasl_methods']
                        ? \implode(', ', $sieveResult['sasl_methods'])
                        : 'no SASL advertised';
                    $output->writeln("  <info>✓ Sieve {$sieveHost}:{$sievePort} ({$sieveAuthStr})</info>");
                    if (!$sieveResult['oauth_supported']) {
                        $output->writeln(
                            '  <error>✗ Sieve server does not advertise OAUTHBEARER/XOAUTH2</error>'
                        );
                        $errors++;
                    }
                    if ($sieveResult['tls_warning'] !== '') {
                        $output->writeln('  <comment>  ↳ ' . $sieveResult['tls_warning'] . '</comment>');
                    }
                } else {
                    $output->writeln("  <error>✗ Sieve {$sieveHost}:{$sievePort} — {$sieveResult['error']}</error>");
                    $errors++;
                }
            }
        } else {
            $output->writeln('  <comment>⊘ Connectivity checks skipped (--skip-checks)</comment>');
        }

        // 3. OIDC
        if ($oidcProvider === null) {
            $output->writeln('  <error>✗ OIDC  user_oidc is not installed</error>');
            $output->writeln('    → occ app:install user_oidc');
            return 1;
        }

        $oidcInfo = $oidcProvider;
        $storeToken = $this->appConfig->getValueString('user_oidc', 'store_login_token', '0');
        if ($storeToken !== '1') {
            $this->appConfig->setValueString('user_oidc', 'store_login_token', '1');
            $oidcInfo .= ', store_login_token=1 (set)';
        } else {
            $oidcInfo .= ', store_login_token=1';
        }
        $output->writeln("  <info>✓ OIDC  {$oidcInfo}</info>");

        $output->writeln('');

        if ($errors > 0 && !$skipChecks) {
            $output->writeln("<error>{$errors} check(s) failed. Fix issues above or use --skip-checks.</error>");
            return 1;
        }

        // ═══════════════════════════════════════════
        // CONFIGURATION
        // ═══════════════════════════════════════════
        $output->writeln('<info>Applying Configuration</info>');
        $output->writeln('');

        // Write domain config
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
        $output->writeln("  Domain config: <comment>{$domain}</comment>");
        $removedDomains = [];
        $cleanupWarnings = [];
        foreach ($this->domainService->listDomains() as $existing) {
            if ($existing !== $domain) {
                try {
                    $this->domainService->deleteDomainConfig($existing);
                    $removedDomains[] = $existing;
                } catch (\Throwable $cleanupError) {
                    $cleanupWarnings[] = $existing;
                    $output->writeln(
                        '  <comment>Cleanup skipped for ' . $existing . ': '
                        . $cleanupError->getMessage() . '</comment>'
                    );
                }
            }
        }
        if ($removedDomains !== []) {
            $output->writeln(
                '  Consolidated single-domain config: <comment>'
                . \implode(', ', $removedDomains) . '</comment> removed'
            );
        }
        if ($cleanupWarnings !== []) {
            $output->writeln(
                '  <comment>Some previous domains could not be removed: '
                . \implode(', ', $cleanupWarnings) . '</comment>'
            );
        }

        // NC app config
        $this->appConfig->setValueString(self::APP_ID, 'autologin', '1');
        $this->appConfig->setValueString(self::APP_ID, 'autologin-oidc', '1');
        $this->appConfig->setValueString(self::APP_ID, self::OIDC_PROVIDER_KEY, $oidcProvider);
        $oidcAudience = $input->getOption('oidc-audience');
        if (\is_string($oidcAudience) && $oidcAudience !== '') {
            $this->appConfig->setValueString(self::APP_ID, 'oidc-exchange-audience', $oidcAudience);
            $output->writeln('  Token exchange audience: <comment>' . $oidcAudience . '</comment>');
        } else {
            $this->appConfig->setValueString(self::APP_ID, 'oidc-exchange-audience', '');
        }
        $oidcScopes = $input->getOption('oidc-scopes');
        $oidcScopes = \is_string($oidcScopes) ? \trim($oidcScopes) : '';
        if ($oidcScopes !== '') {
            $this->appConfig->setValueString(self::APP_ID, 'oidc-exchange-scopes', $oidcScopes);
            $output->writeln('  Token exchange scopes: <comment>' . $oidcScopes . '</comment>');
        } else {
            $this->appConfig->setValueString(self::APP_ID, 'oidc-exchange-scopes', '');
        }

        // Engine config (app_path, default_domain)
        $appDir = \dirname(\dirname(__DIR__)) . '/app';
        if (\is_dir($appDir)) {
            try {
                $this->engineHelper->loadApp();
                $oConfig = \X2Mail\Engine\Api::Config();
                $webPath = $this->appManager->getAppWebPath(self::APP_ID);
                $appPath = \preg_replace(
                    '#(?<!:)/+#',
                    '/',
                    \rtrim($webPath, '/') . '/app/'
                );
                // Only set domain + app_path — all other defaults handled by InstallStep
                $oConfig->Set('webmail', 'app_path', $appPath);
                $oConfig->Set('login', 'default_domain', $domain);
                $oConfig->Save();
                $output->writeln("  Engine app_path: <comment>{$appPath}</comment>");
            } catch (\Throwable $e) {
                $output->writeln('  <comment>Engine config skipped: ' . $e->getMessage() . '</comment>');
            }
        }

        // ═══════════════════════════════════════════
        // SUMMARY
        // ═══════════════════════════════════════════
        $output->writeln('');
        $output->writeln('<info>Setup complete!</info>');
        $output->writeln('');
        $output->writeln('  Domain:    ' . $domain);
        $output->writeln('  IMAP:      ' . $imapHost . ':' . $imapPort . ' (' . \strtoupper($imapSsl) . ')');
        $output->writeln('  SMTP:      ' . $smtpHost . ':' . $smtpPort . ' (' . \strtoupper($smtpSsl) . ')');
        if ($sieve) {
            $output->writeln(
                '  Sieve:     enabled (' . $sieveHost . ':' . $sievePort . ' ' . \strtoupper($sieveSsl) . ')'
            );
        } else {
            $output->writeln('  Sieve:     disabled');
        }
        $output->writeln('  Auth:      OAUTHBEARER (SSO)');
        $output->writeln('  OIDC:      ' . $oidcProvider);

        $output->writeln('');
        $output->writeln('<comment>Mail server requirements (your responsibility):</comment>');
        $output->writeln('  1. IMAP and SMTP submission must support OAUTHBEARER or XOAUTH2 SASL');
        $output->writeln('  2. IMAP/SMTP server must validate tokens against your OIDC provider (e.g. Keycloak)');
        $output->writeln('     → Dovecot/Postfix: configure oauth2 validation or token introspection');
        $output->writeln('  3. OIDC provider must include correct audience in access tokens');
        $output->writeln('     → Keycloak: add audience mapper to the Nextcloud client');
        $output->writeln('  4. IMAP username must match the email claim in the OIDC token');

        return 0;
    }
}
