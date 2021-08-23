<?php

namespace App\Factory;

use FilesystemIterator;

/**
 * Factory.
 */
class FilesystemIteratorFactory
{
    private string $root;

    /**
     * The constructor.
     *
     * @param string $path the root path to the tcms-data folder
     */
    public function __construct(string $path)
    {
        $this->root = $path;
    }

    /**
     * Generate the File Interator.
     *
     * @param string $subpath (optional) subfolder to interate through
     *
     * @return FilesystemIterator
     */
    private function createIterator(string $subpath = ''): FilesystemIterator
    {
        return new FilesystemIterator($this->buildPath($subpath), FilesystemIterator::SKIP_DOTS);
    }

    /**
     * Generate the File Interator.
     *
     * @param string $subpath (optional) subfolder to interate through
     *
     * @return string
     */
    public function buildPath(string $subpath = ''): string
    {
        $path = $this->root;
        if (!empty($subpath)) {
            $path .= DIRECTORY_SEPARATOR . $subpath;
        }

        return $path;
    }

    /**
     * Read file.
     *
     * @param string $subpath (optional) path of file to read
     *
     * @return ?string
     */
    public function readFile(string $subpath = ''): ?string
    {
        $path = $this->buildPath($subpath);
        if (!file_exists($path)) {
            return null;
        }
        $contents = file_get_contents($path);

        return is_string($contents) ? $contents : null;
    }

    /**
     * file exists.
     *
     * @param string $subpath (optional) path of file to read
     *
     * @return bool
     */
    public function exists(string $subpath = ''): bool
    {
        $path = $this->buildPath($subpath);

        return file_exists($path);
    }

    /**
     * recursively make a directory.
     *
     * @param string $dir full path to the directory to make
     *
     * @return bool
     */
    private static function makeDir(string $dir): bool
    {
        if (!file_exists($dir)) {
            return mkdir($dir, 0775, true);
        }

        return true;
    }

    // public function delete(string $path) : bool
    // {
    //     if (file_exists($path)) {
    //         if (is_dir($path)) {
    //             return self::recursiveDelete($path);
    //         }
    //         return unlink($path);
    //     }
    //     return true;
    // }

    // public static function recursiveDelete(string $source, bool $removeOnlyChildren = false) : bool
    // {
    //     if (empty($source) || file_exists($source) === false) {
    //         return false;
    //     }
    //     if (is_file($source) || is_link($source)) {
    //         return unlink($source);
    //     }

    //     $dirIt  = new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS);
    //     $files  = new RecursiveIteratorIterator($dirIt, RecursiveIteratorIterator::CHILD_FIRST);

    //     foreach ($files as $fileinfo) {
    //         if ($fileinfo->isDir()) {
    //             if (self::recursiveDelete($fileinfo->getRealPath()) === false) {
    //                 return false;
    //             }
    //         }
    //         if (unlink($fileinfo->getRealPath()) === false) {
    //             return false;
    //         }
    //     }
    //     if ($removeOnlyChildren === false) {
    //         return rmdir($source);
    //     }
    //     return true;
    // }

    /**
     * Generate the File Interator.
     *
     * @param string $subpath path of file to write to
     * @param string $data    data to wrtie to the file
     *
     * @return bool
     */
    public function saveFile(string $subpath, string $data): bool
    {
        $path = $this->buildPath($subpath);
        $this::makeDir(dirname($path));

        return file_put_contents($path, $data, LOCK_EX) !== false;
    }

    /**
     * Interate through folder and return directories.
     *
     * @param mixed $fileinfo fileinfo
     *
     * @return ?string
     */
    private static function filterFile($fileinfo): ?string
    {
        // Verify that its a SplFileInfo
        if (!is_a($fileinfo, 'SplFileInfo')) {
            return null;
        }
        $basename = $fileinfo->getBasename();
        // ignore any names that begin with dots
        if (mb_strpos($basename, '.') === 0) {
            return null;
        }

        return $basename;
    }

    /**
     * Interate through folder and return directories.
     *
     * @param string $subfolder (optional) subfolder to interate through
     *
     * @return array<string>
     */
    public function list(string $subfolder = ''): array
    {
        $files = [];
        foreach ($this->createIterator($subfolder) as $fileinfo) {
            // Verify file
            $basename = $this::filterFile($fileinfo);
            if (empty($basename)) {
                continue;
            }
            $files[] = $basename;
        }

        return $files;
    }

    /**
     * Interate through folder and return directories.
     *
     * @param string $subfolder (optional) subfolder to interate through
     *
     * @return array<string>
     */
    public function listDirs(string $subfolder = ''): array
    {
        $files = [];
        foreach ($this->createIterator($subfolder) as $fileinfo) {
            // Verify that its a SplFileInfo
            if (!is_a($fileinfo, 'SplFileInfo')) {
                continue;
            }
            // Verify file
            $basename = $this::filterFile($fileinfo);
            if (empty($basename)) {
                continue;
            }
            // All collections are the top level folders in tcms-data
            if ($fileinfo->isFile() === true) {
                continue;
            }
            $files[] = $basename;
        }

        return $files;
    }

    /**
     * Interate through folder and return directories.
     *
     * @param string        $subfolder  (optional) subfolder to interate through
     * @param array<string> $extensions (optional) array of file extensions to include
     *
     * @return array<string>
     */
    public function listFiles(string $subfolder = '', array $extensions = []): array
    {
        $files = [];
        foreach ($this->createIterator($subfolder) as $fileinfo) {
            // Verify that its a SplFileInfo
            if (!is_a($fileinfo, 'SplFileInfo')) {
                continue;
            }
            // Verify file
            $basename = $this::filterFile($fileinfo);
            if (empty($basename)) {
                continue;
            }
            // All collections are the top level folders in tcms-data
            if ($fileinfo->isDir() === true) {
                continue;
            }
            // only include certain file extensions
            if (!empty($extensions) && !in_array($fileinfo->getExtension(), $extensions)) {
                continue;
            }
            $files[] = $basename;
        }

        return $files;
    }
}
