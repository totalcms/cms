<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Skill\Service;

/**
 * Installs (or refreshes) the bundled Total CMS agent skill into a project's
 * `.claude/skills/totalcms/` directory.
 *
 * The skill source ships inside the package at `resources/skill/`. Because Claude
 * Code only discovers skills under a project's `.claude/skills/` (never inside
 * `vendor/`), the files must be copied out of the package and into the project
 * root. This service is the copy step — wired to the `skill:install` CLI command
 * and run by the project skeleton's Composer lifecycle hooks so it stays current
 * on every install/update.
 *
 * The skill is core-owned: install overwrites by default (like the shipped docs),
 * so customers always get the version matching their installed `totalcms/cms`.
 *
 * The shipped source is written for the Composer layout (`vendor/bin/tcms`,
 * `vendor/totalcms/cms/resources/docs/`). Zip installs have neither: the CMS *is*
 * the app folder, so the CLI is `php resources/bin/tcms` and the docs are
 * `resources/docs/`. Passing `$composerInstall = false` rewrites those two path
 * forms in every `.md` file as it is copied, so the installed skill always
 * describes the layout it landed in. Lines carrying the KEEP_MARKER comment are
 * copied verbatim — that is how the skill can document both layouts side by side.
 */
class SkillInstaller
{
	/** Marks a line whose Composer paths must survive the zip rewrite. */
	public const KEEP_MARKER = '<!-- composer-paths -->';

	/** Composer-layout path forms rewritten for zip installs, in order. */
	private const ZIP_REWRITES = [
		'vendor/bin/tcms'       => 'php resources/bin/tcms',
		'vendor/totalcms/cms/'  => '',
	];

	/**
	 * Copy the skill tree from $source into $target.
	 *
	 * @param bool $composerInstall False rewrites Composer paths in `.md` files for the zip layout.
	 *
	 * @return array{installed: bool, source: string, target: string, copied: list<string>, failed: list<string>}
	 */
	public function install(string $source, string $target, bool $force = true, bool $composerInstall = true): array
	{
		$source = rtrim($source, '/');
		$target = rtrim($target, '/');

		$result = ['installed' => false, 'source' => $source, 'target' => $target, 'copied' => [], 'failed' => []];

		if (!is_dir($source)) {
			return $result;
		}

		$copied = [];
		$failed = [];

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
					$failed[] = $relative;
				}
				continue;
			}

			if (!$force && file_exists($dest)) {
				continue;
			}

			$destDir = dirname($dest);
			if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
				$failed[] = $relative;
				continue;
			}

			if (!$this->copyFile($item->getPathname(), $dest, $composerInstall)) {
				$failed[] = $relative;
				continue;
			}

			$copied[] = $relative;
		}

		$result['installed'] = true;
		$result['copied']    = $copied;
		$result['failed']    = $failed;

		return $result;
	}

	/**
	 * Copy one file, rewriting markdown paths when the target is a zip install.
	 */
	private function copyFile(string $from, string $dest, bool $composerInstall): bool
	{
		if ($composerInstall || strtolower(pathinfo($from, PATHINFO_EXTENSION)) !== 'md') {
			return @copy($from, $dest);
		}

		$contents = @file_get_contents($from);
		if ($contents === false) {
			return false;
		}

		return @file_put_contents($dest, $this->rewriteForZip($contents)) !== false;
	}

	/**
	 * Swap Composer-layout paths for their zip-install equivalents, line by line
	 * so KEEP_MARKER lines can document the Composer layout verbatim.
	 */
	private function rewriteForZip(string $contents): string
	{
		$lines = explode("\n", $contents);

		foreach ($lines as $index => $line) {
			if (str_contains($line, self::KEEP_MARKER)) {
				continue;
			}

			$lines[$index] = str_replace(
				array_keys(self::ZIP_REWRITES),
				array_values(self::ZIP_REWRITES),
				$line,
			);
		}

		return implode("\n", $lines);
	}
}
