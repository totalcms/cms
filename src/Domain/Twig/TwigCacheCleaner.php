<?php

namespace TotalCMS\Domain\Twig;

use TotalCMS\Support\Config;

/**
 * Twig template processor.
 */
final class TwigCacheCleaner
{
    public function __construct(
        private Config $config,
    ) {
    }

    private function deleteDir(string $dir): bool
    {
        if (!file_exists($dir)) {
            return true;
        }

        if (!is_dir($dir)) {
            return unlink($dir);
        }

        $dirs = scandir($dir);
        if ($dirs === false) {
            return false;
        }

        foreach ($dirs as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }

            if (!self::deleteDir($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }

        return rmdir($dir);
    }

    public function deleteCache(): bool
    {
        $cacheDir = $this->config->cacheDir;

        self::deleteDir($cacheDir);

        return !file_exists($cacheDir);
    }
}
