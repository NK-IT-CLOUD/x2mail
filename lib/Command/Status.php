<?php

declare(strict_types=1);

namespace OCA\X2Mail\Command;

use OCA\X2Mail\Service\DomainConfigService;
use OCA\X2Mail\Service\LogService;
use OCA\X2Mail\Util\EngineHelper;
use Symfony\Component\Console\Command\Command;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Status extends Command
{
    private const APP_ID = 'x2mail';

    public function __construct(
        private IAppConfig $appConfig,
        private DomainConfigService $domainService,
        private IAppManager $appManager,
        private LogService $logService,
        private EngineHelper $engineHelper,
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('x2mail:status')
            ->setDescription('Show X2Mail configuration status')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>X2Mail Status</info>');
        $output->writeln('');

        // Domains
        $domains = $this->domainService->listDomains();
        $output->writeln('<comment>Configured Domains:</comment>');
        if (empty($domains)) {
            $output->writeln('  (none)');
        } else {
            foreach ($domains as $domain) {
                $config = $this->domainService->readDomainConfig($domain);
                if ($config) {
                    $imapHost = $config['IMAP']['host'] ?? '?';
                    $imapPort = $config['IMAP']['port'] ?? '?';
                    $imapType = $config['IMAP']['type'] ?? 0;
                    $imapSsl = \is_int($imapType) ? DomainConfigService::sslToString($imapType) : 'custom';
                    $smtpHost = $config['SMTP']['host'] ?? '?';
                    $smtpPort = $config['SMTP']['port'] ?? '?';
                    $smtpType = $config['SMTP']['type'] ?? 0;
                    $smtpSsl = \is_int($smtpType) ? DomainConfigService::sslToString($smtpType) : 'custom';
                    $sasl = $config['IMAP']['sasl'] ?? [];
                    $hasOAuth = \in_array('OAUTHBEARER', $sasl) || \in_array('XOAUTH2', $sasl);
                    $authMode = $hasOAuth ? 'OAUTHBEARER/XOAUTH2' : 'unknown';
                    $sieve = ($config['Sieve']['enabled'] ?? false) ? 'yes' : 'no';
                    $output->writeln("  {$domain}");
                    $output->writeln("    IMAP: {$imapHost}:{$imapPort} ({$imapSsl})");
                    $output->writeln("    SMTP: {$smtpHost}:{$smtpPort} ({$smtpSsl})");
                    $output->writeln("    Auth: {$authMode}");
                    $output->writeln("    Sieve: {$sieve}");
                } else {
                    $output->writeln("  {$domain} (config unreadable)");
                }
            }
            if (\count($domains) > 1) {
                $output->writeln('');
                $output->writeln(
                    '  <comment>Warning: release branch uses one active domain.'
                    . ' Re-run occ x2mail:setup or save in the setup wizard to consolidate.</comment>'
                );
            }
        }

        $output->writeln('');

        // SSO/OIDC status
        $output->writeln('<comment>SSO Configuration:</comment>');
        $oidcEnabled = $this->appConfig->getValueString(self::APP_ID, 'autologin-oidc', '0') === '1';
        $autologin = $this->appConfig->getValueString(self::APP_ID, 'autologin', '0') === '1';

        $userOidc = $this->appManager->isEnabledForUser('user_oidc');
        $provider = $userOidc ? 'user_oidc' : 'none';

        $output->writeln('  OIDC auto-login: ' . ($oidcEnabled ? '<info>enabled</info>' : 'disabled'));
        $output->writeln('  Autologin:       ' . ($autologin ? '<info>enabled</info>' : 'disabled'));
        $providerLabel = $provider === 'none'
            ? '<error>user_oidc not installed</error>'
            : "<info>{$provider}</info>";
        $output->writeln('  Provider:        ' . $providerLabel);

        if ($userOidc) {
            $storeToken = $this->appConfig->getValueString(
                'user_oidc',
                'store_login_token',
                '0'
            );
            $tokenLabel = $storeToken === '1'
                ? '<info>enabled</info>'
                : '<error>disabled (set store_login_token=1)</error>';
            $output->writeln('  Token store:     ' . $tokenLabel);

            try {
                $hasProvider = $this->appConfig->getValueString(
                    'user_oidc',
                    'provider-1-mappingUid',
                    '',
                    lazy: true
                ) !== '';
                $providerStatus = $hasProvider
                    ? '<info>configured</info>'
                    : '<comment>not detected (occ user_oidc:provider)</comment>';
                $output->writeln('  OIDC provider:   ' . $providerStatus);
            } catch (\Throwable $e) {
                // Cannot check — skip
            }
        }

        $output->writeln('');
        $output->writeln(
            '  <comment>Token diagnostics only available in browser wizard'
            . ' (Admin → X2Mail → Run Checks)</comment>'
        );

        $output->writeln('');

        // Engine version and app_path
        $output->writeln('<comment>X2Mail Engine:</comment>');
        $appDir = \dirname(\dirname(__DIR__)) . '/app';
        if (\is_dir($appDir)) {
            try {
                $this->engineHelper->loadApp();
                $output->writeln('  Version: ' . APP_VERSION);
                $appPath = \X2Mail\Engine\Api::Config()->Get('webmail', 'app_path', '(not set)');
                $output->writeln('  app_path: ' . $appPath);
            } catch (\Throwable $e) {
                $output->writeln('  <error>Failed to load engine: ' . $e->getMessage() . '</error>');
            }
        } else {
            $output->writeln('  <comment>Engine not present at app/</comment>');
        }

        $output->writeln('');

        // Debug log status
        $output->writeln('<comment>Debug Log:</comment>');
        $debugEnabled = $this->logService->isEnabled();
        $output->writeln('  Status: ' . ($debugEnabled ? '<info>enabled</info>' : 'disabled'));
        $output->writeln('  File: ' . $this->domainService->getDataPath() . '/x2mail.log');
        $output->writeln('  Toggle: occ config:app:set x2mail debug_log --value=1|0');

        $output->writeln('');
        $output->writeln('  Data path: ' . $this->domainService->getDataPath());

        return 0;
    }
}
