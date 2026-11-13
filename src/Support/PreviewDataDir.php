<?php

declare(strict_types=1);

namespace TotalCMS\Support;

/**
 * Where Stacks Preview keeps its tcms-data: a Stacks-TotalCMS folder in the
 * first temp folder PHP can actually write to.
 *
 * sys_get_temp_dir() is the php.ini sys_temp_dir, else $TMPDIR, else macOS's
 * built-in /var/tmp. RapidWeaver runs sandboxed and sets $TMPDIR to its
 * container's tmp folder, so preview normally lands there. A PHP that never
 * receives $TMPDIR (a custom PHP binary picked in RapidWeaver, or one whose
 * php.ini sets sys_temp_dir) answers /var/tmp, which the sandbox will not
 * let it write to, and Preview rendered a blank page.
 *
 * Each candidate is tested by writing to it: the sandbox refuses writes that
 * is_writable() still reports as allowed, since /var/tmp is world-writable.
 */
final class PreviewDataDir
{
	public const FOLDER = 'Stacks-TotalCMS';

	/**
	 * @param string                    $fallback   used when no candidate takes a write: the preview's own tcms folder
	 * @param list<string|false|null>|null $candidates parent folders to try in order; null for the defaults
	 */
	public static function resolve(string $fallback, ?array $candidates = null): string
	{
		foreach ($candidates ?? self::candidates() as $parent) {
			if (!is_string($parent) || $parent === '') {
				continue;
			}
			$dir = rtrim($parent, '/') . '/' . self::FOLDER;
			if (self::writable($dir)) {
				return $dir;
			}
		}

		return rtrim($fallback, '/') . '/' . self::FOLDER;
	}

	/**
	 * @return list<string|false|null>
	 */
	public static function candidates(): array
	{
		$home = getenv('HOME');

		return [
			sys_get_temp_dir(),
			getenv('TMPDIR'),
			// A sandboxed app's HOME is its container's Data folder, and the
			// app's own temp folder is tmp inside it.
			is_string($home) && str_contains($home, '/Library/Containers/') ? $home . '/tmp' : null,
		];
	}

	private static function writable(string $dir): bool
	{
		return QuietCall::run(static function () use ($dir): bool {
			if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
				return false;
			}
			$probe = $dir . '/.write-test-' . getmypid();
			if (file_put_contents($probe, '') === false) {
				return false;
			}
			unlink($probe);

			return true;
		});
	}
}
