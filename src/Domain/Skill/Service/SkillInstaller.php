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
 */
class SkillInstaller
{
	/**
	 * Copy the skill tree from $source into $target.
	 *
	 * @return array{installed: bool, source: string, target: string, copied: list<string>, failed: list<string>}
	 */
	public function install(string $source, string $target, bool $force = true): array
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

			if (!@copy($item->getPathname(), $dest)) {
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
}
