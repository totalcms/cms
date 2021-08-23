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
     * Generate the File Iterator.
     *
     * @param string $directory (optional) sub-folder to iterate through
     *
     * @return FilesystemIterator
     */
    private function createIterator(string $directory = ''): FilesystemIterator
    {
        return new FilesystemIterator($this->buildPath($directory), FilesystemIterator::SKIP_DOTS);
    }

    /**
     * Generate the File Iterator.
     *
     * @param string $directory (optional) sub-folder to iterate through
     *
     * @return string
     */
    public function buildPath(string $directory = ''): string
    {
        $path = $this->root;
        if (!empty($directory)) {
            $path .= DIRECTORY_SEPARATOR . $directory;
        }

        return $path;
    }

    /**
     * Read file.
     *
     * @param string $filename (optional) path of file to read
     *
     * @return ?string
     */
    public function readFile(string $filename = ''): ?string
    {
        $path = $this->buildPath($filename);

        if (!file_exists($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return is_string($contents) ? $contents : null;
    }

    /**
     * File exists.
     *
     * @param string $filename (optional) path of file to read
     *
     * @return bool
     */
    public function exists(string $filename = ''): bool
    {
        $path = $this->buildPath($filename);

        return file_exists($path);
    }

    /**
     * recursively make a directory.
     *
     * @param string $directory full path to the directory to make
     *
     * @return bool
     */
    private static function makeDir(string $directory): bool
    {
        if (!file_exists($directory)) {
            return mkdir($directory, 0775, true);
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
     * Generate the File Iterator.
     *
     * @param string $filename path of file to write to
     * @param string $data data to write to the file
     *
     * @return bool
     */
    public function saveFile(string $filename, string $data): bool
    {
        $path = $this->buildPath($filename);
        $this::makeDir(dirname($path));

        return file_put_contents($path, $data, LOCK_EX) !== false;
    }

    /**
     * Iterate through folder and return directories.
     *
     * @param mixed $file The file
     *
     * @return ?string
     */
    private function filterFile($file): ?string
    {
        // Verify that its a SplFileInfo
        if (!is_a($file, 'SplFileInfo')) {
            return null;
        }

        $basename = $file->getBasename();

        // ignore any names that begin with dots
        if (mb_strpos($basename, '.') === 0) {
            return null;
        }

        return $basename;
    }

    /**
     * Iterate through folder and return directories.
     *
     * @param string $directory (optional) sub-folder to iterate through
     *
     * @return array<string>
     */
    public function list(string $directory = ''): array
    {
        $files = [];

        foreach ($this->createIterator($directory) as $file) {
            // Verify file
            $basename = $this->filterFile($file);
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
     * @param string $directory (optional) subfolder to interate through
     *
     * @return array<string>
     */
    public function listDirs(string $directory = ''): array
    {
        $files = [];
        foreach ($this->createIterator($directory) as $fileinfo) {
            // Verify that its a SplFileInfo
            if (!is_a($fileinfo, 'SplFileInfo')) {
                continue;
            }

            // Verify file
            $basename = $this->filterFile($fileinfo);
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
     * Iterate through folder and return directories.
     *
     * @param string $directory (optional) subfolder to interate through
     * @param array<string> $extensions (optional) array of file extensions to include
     *
     * @return array<string>
     */
    public function listFiles(string $directory = '', array $extensions = []): array
    {
        $files = [];
        foreach ($this->createIterator($directory) as $fileinfo) {
            // Verify that its a SplFileInfo
            if (!is_a($fileinfo, 'SplFileInfo')) {
                continue;
            }

            // Verify file
            $basename = $this->filterFile($fileinfo);
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
