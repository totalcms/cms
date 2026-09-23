<?php

declare(strict_types=1);

namespace TotalCMS\Infrastructure\Filesystem;

class FileUtils
{
	public static function fileSizeString(int $size): string
	{
		$units = ['B', 'KB', 'MB', 'GB', 'TB'];
		$unit  = 0;

		while ($size >= 1024) {
			$size /= 1024;
			$unit++;
		}

		return sprintf('%.1f %s', $size, $units[$unit]);
	}

	/**
	 * Copy a directory tree. Directories are created as needed; a file that
	 * already exists is skipped unless `$force`. `$copy` replaces the plain
	 * copy() for callers that transform files on the way (the skill installer
	 * rewrites paths in Markdown for the zip layout); it returns false to
	 * report a failure. Paths in the result are relative to `$source`.
	 *
	 * @param callable(string, string): bool|null $copy
	 *
	 * @return array{copied: list<string>, skipped: list<string>, failed: list<string>}
	 */
	public static function copyTree(string $source, string $target, bool $force = true, ?callable $copy = null): array
	{
		$source = rtrim($source, DIRECTORY_SEPARATOR);
		$target = rtrim($target, DIRECTORY_SEPARATOR);
		$result = ['copied' => [], 'skipped' => [], 'failed' => []];

		if (!is_dir($source)) {
			return $result;
		}

		$copy ??= static fn (string $from, string $to): bool => @copy($from, $to);

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST,
		);

		foreach ($iterator as $item) {
			if (!$item instanceof \SplFileInfo) {
				continue;
			}

			$relative = ltrim(str_replace($source, '', $item->getPathname()), DIRECTORY_SEPARATOR);
			$dest     = $target . DIRECTORY_SEPARATOR . $relative;

			if ($item->isDir()) {
				if (!is_dir($dest) && !mkdir($dest, 0755, true) && !is_dir($dest)) {
					$result['failed'][] = $relative;
				}
				continue;
			}

			if (!$force && file_exists($dest)) {
				$result['skipped'][] = $relative;
				continue;
			}

			$destDir = dirname($dest);
			if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
				$result['failed'][] = $relative;
				continue;
			}

			$result[$copy($item->getPathname(), $dest) ? 'copied' : 'failed'][] = $relative;
		}

		return $result;
	}

	/**
	 * A php.ini size (`8M`, `512K`, `1G`, `1000`) in bytes; empty is 0.
	 */
	public static function iniSizeToBytes(string $value): int
	{
		$value = trim($value);
		if ($value === '') {
			return 0;
		}

		$number = (int)$value;
		$unit   = strtolower($value[strlen($value) - 1]);

		return match ($unit) {
			'g'     => $number * 1024 * 1024 * 1024,
			'm'     => $number * 1024 * 1024,
			'k'     => $number * 1024,
			default => $number,
		};
	}
}
