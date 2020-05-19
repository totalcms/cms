<?php

namespace App\Factory;

use FilesystemIterator;

/**
 * Factory.
 */
class DataDirIteratorFactory
{
    private string $path;

    /**
     * The constructor.
     *
     * @param string $path the path to the tcms-data folder
     */
    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * Generate the File Interator
     *
     * @param string $subfolder (optional) subfolder to interate through
     *
     * @return FilesystemIterator
     */
    private function createIterator(string $subfolder = ''): FilesystemIterator
    {
        $dir = $this->path;
        if (!empty($subfolder)) {
            $dir .= "/$subfolder";
        }
        return new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
    }

    /**
     * Interate through folder and return directories
     *
     * @param string $subfolder (optional) subfolder to interate through
     *
     * @return string[]
     */
    public function dirs(string $subfolder = ''): array
    {
        $files = [];
        foreach ($this->createIterator($subfolder) as $fileinfo) {
            // Verify that its a SplFileInfo
            if (!is_a($fileinfo, 'SplFileInfo')) {
                continue;
            }
            // All collections are the top level folders in tcms-data
            if ($fileinfo->isFile() === true) {
                continue;
            }
            $basename = $fileinfo->getBasename();
            // ignore any names that begin with dots
            if (mb_strpos($basename, '.') === 0) {
                continue;
            }
            $files[] = $basename;
        }
        return $files;
    }

    /**
     * Interate through folder and return directories
     *
     * @param string   $subfolder  (optional) subfolder to interate through
     * @param string[] $extensions (optional) array of file extensions to include
     *
     * @return string[]
     */
    public function files(string $subfolder = '', array $extensions = []): array
    {
        $files = [];
        foreach ($this->createIterator($subfolder) as $fileinfo) {
            // Verify that its a SplFileInfo
            if (!is_a($fileinfo, 'SplFileInfo')) {
                continue;
            }
            // All collections are the top level folders in tcms-data
            if ($fileinfo->isDir() === true) {
                continue;
            }
            $basename = $fileinfo->getBasename();
            // ignore any names that begin with dots
            if (mb_strpos($basename, '.') === 0) {
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
