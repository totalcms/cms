<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Service;

use TotalCMS\Support\OperationResult;
use TotalCMS\Support\PathResolver;

/**
 * Installs a Vite-based frontend asset pipeline scaffold into a project's
 * `frontend/` directory. The scaffold ships in `resources/builder/frontend/`
 * and is the same for every starter — there's nothing starter-specific
 * about the build pipeline.
 *
 * Idempotent: existing files are skipped unless `$force` is set, so users
 * can safely re-run the installer to pull missing files without losing
 * customizations.
 */
readonly class BuilderFrontendInstaller
{
	public function install(bool $force = false): OperationResult
	{
		$source = $this->scaffoldDir();
		if (!is_dir($source)) {
			return OperationResult::failure("Frontend scaffold missing: {$source}");
		}

		$target = $this->targetDir();
		if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
			return OperationResult::failure("Could not create target directory: {$target}");
		}

		$copied  = [];
		$skipped = [];
		$failed  = [];

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
				$skipped[] = $relative;

				continue;
			}

			if (!@copy($item->getPathname(), $dest)) {
				$failed[] = $relative;

				continue;
			}

			$copied[] = $relative;
		}

		if ($failed !== []) {
			return OperationResult::failure(
				'Some files could not be copied: ' . implode(', ', $failed),
				null,
				['copied' => $copied, 'skipped' => $skipped, 'failed' => $failed, 'target' => $target],
			);
		}

		$summary = sprintf(
			'%d file(s) copied, %d skipped (already present)',
			count($copied),
			count($skipped),
		);
		if ($skipped !== [] && !$force) {
			$summary .= ' — re-run with --force to overwrite';
		}

		return OperationResult::success($summary, [
			'copied'  => $copied,
			'skipped' => $skipped,
			'target'  => $target,
		]);
	}

	private function scaffoldDir(): string
	{
		return PathResolver::packageRoot() . '/resources/builder/frontend';
	}

	private function targetDir(): string
	{
		return PathResolver::projectRoot() . '/frontend';
	}
}
