<?php

namespace OCA\X2Mail\Util;

class RainLoop
{
    /**
     * Imports data from RainLoop
     *
     * @return list<string>
     */
    public static function import(): array
    {
        $dir = \rtrim(\trim(\OCP\Server::get(\OCP\IConfig::class)->getSystemValue('datadirectory', '')), '\\/');
        $dir_snappy = $dir . '/appdata_x2mail/';
        $dir_rainloop = $dir . '/rainloop-storage';
        $result = [];
        $rainloop_plugins = [];
        if (\is_dir($dir_rainloop)) {
            \is_dir($dir_snappy) || \mkdir($dir_snappy, 0755, true);
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir_rainloop, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $item) {
                $target = $dir_snappy . $iterator->getSubPathname();
                if (\preg_match('@/plugins/([^/]+)@', $target, $match)) {
                    $rainloop_plugins[$match[1]] = $match[1];
                } elseif (!\strpos($target, '/cache/')) {
                    if ($item->isDir()) {
                        \is_dir($target) || \mkdir($target, 0755, true);
                    } elseif (\file_exists($target)) {
                        $result[] = "skipped: {$target}";
                    } else {
                        \copy($item, $target);
                        $result[] = "copied : {$target}";
                    }
                }
            }
        }

        SnappyMailHelper::loadApp();

        // Attempt to install same plugins as RainLoop
        if ($rainloop_plugins) {
            foreach (\SnappyMail\Repository::getPackagesList()['List'] as $plugin) {
                if (\in_array($plugin['id'], $rainloop_plugins)) {
                    $result[] = "install plugin : {$plugin['id']}";
                    \SnappyMail\Repository::installPackage('plugin', $plugin['id']);
                    unset($rainloop_plugins[$plugin['id']]);
                }
            }
            foreach ($rainloop_plugins as $plugin) {
                $result[] = "skipped plugin : {$plugin}";
            }
        }

        $oConfig = \RainLoop\Api::Config();
        $oConfig->Set('webmail', 'theme', 'x2mail');
        $oConfig->Save();

        return $result;
    }
}
