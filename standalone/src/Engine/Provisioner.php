<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Engine;

use X2Mail\Standalone\Config\Config;

/**
 * One-shot engine setup for the standalone webmail (container start):
 * engine data dir, release defaults, one OAuth domain for the mail host as
 * login.default_domain (every mail domain falls back to it, since 0.8.2),
 * and the bundled engine plugin. Idempotent.
 */
final class Provisioner
{
    public function __construct(
        private Config $config,
        private string $engineIndex,
        private string $pluginSource,
    ) {
    }

    public function run(): void
    {
        $this->validateHost();
        $this->bootEngine();
        $oConfig = \X2Mail\Engine\Api::Config();

        $oConfig->Set('webmail', 'app_path', '/');
        $oConfig->Set('webmail', 'title', 'X2Mail');
        $oConfig->Set('webmail', 'loading_description', 'X2Mail');
        $oConfig->Set('webmail', 'theme', 'x2mail');
        $oConfig->Set('webmail', 'allow_additional_identities', true);
        $oConfig->Set('security', 'custom_server_signature', 'X2Mail');
        $oConfig->Set('imap', 'show_login_alert', false);
        $oConfig->Set('login', 'default_domain', $this->config->mail->host);
        $oConfig->Set('plugins', 'enable', true);
        $list = \array_values(\array_filter(\array_map('trim', \explode(',', (string) $oConfig->Get('plugins', 'enabled_list', '')))));
        if (!\in_array('standalone', $list, true)) {
            $list[] = 'standalone';
        }
        $oConfig->Set('plugins', 'enabled_list', \implode(',', $list));
        if (!$oConfig->Save()) {
            throw new \RuntimeException('engine config could not be saved');
        }

        $this->writeDomain();
        $this->installPlugin();
    }

    private function validateHost(): void
    {
        $host = $this->config->mail->host;
        if (!\preg_match('/\A[a-z0-9.\-]+\z/i', $host)) {
            throw new \RuntimeException("mail.host '{$host}' is not a host name");
        }
    }

    private function bootEngine(): void
    {
        $cache = $this->config->dataDir . '/cache';
        if (!\is_dir($cache) && !\mkdir($cache, 0750, true)) {
            throw new \RuntimeException("cannot create {$cache}");
        }
        if (!\defined('APP_DATA_FOLDER_PATH')) {
            $engineData = $this->config->dataDir . '/engine/';
            if (!\is_dir($engineData) && !\mkdir($engineData, 0750, true)) {
                throw new \RuntimeException("cannot create {$engineData}");
            }
            \define('APP_DATA_FOLDER_PATH', $engineData);
        }
        $_ENV['X2MAIL_INCLUDE_AS_API'] = true;
        require_once $this->engineIndex;
    }

    private function writeDomain(): void
    {
        $dir = APP_PRIVATE_DATA . 'domains';
        if (!\is_dir($dir) && !\mkdir($dir, 0750, true)) {
            throw new \RuntimeException("cannot create {$dir}");
        }
        $host = $this->config->mail->host;
        $json = \json_encode(MailDomainConfig::build($this->config->mail), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        if (\file_put_contents("{$dir}/{$host}.json", $json) === false) {
            throw new \RuntimeException("cannot write domain config for {$host}");
        }
    }

    private function installPlugin(): void
    {
        if (!\is_dir($this->pluginSource) || !\is_readable($this->pluginSource)) {
            throw new \RuntimeException("plugin source {$this->pluginSource} is not a readable directory");
        }

        $target = APP_PLUGINS_PATH . 'standalone';
        if (\is_dir($target)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
                $path = $item->getPathname();
                if ($item->isDir() ? !\rmdir($path) : !\unlink($path)) {
                    throw new \RuntimeException("cannot remove {$path}");
                }
            }
            if (!\rmdir($target)) {
                throw new \RuntimeException("cannot remove {$target}");
            }
        }
        if (!\is_dir($target) && !@\mkdir($target, 0750, true)) {
            throw new \RuntimeException("cannot create {$target}");
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->pluginSource, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $dest = $target . '/' . \substr($item->getPathname(), \strlen($this->pluginSource) + 1);
            if ($item->isDir() ? !\mkdir($dest, 0750, true) : !\copy($item->getPathname(), $dest)) {
                throw new \RuntimeException("cannot install plugin file {$dest}");
            }
        }
    }
}
