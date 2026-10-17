<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service\Install;

use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Support\QuietCall;

/**
 * Builds the distributable zip for an extension directory:
 * `{name}-{version}.zip` with every entry under a single `{name}/` folder —
 * exactly the shape ExtensionArchive accepts first, so what a developer
 * packages here is what an operator uploads there, and what the marketplace
 * will ask sellers for.
 *
 * Deliberately dumb: no ignore file, no Composer run. `vendor/` is included
 * as found because a sideloaded extension loads its own autoloader. The
 * excludes are the things that are never part of a release.
 */
final readonly class ExtensionPackager
{
	/** Directory or file names skipped at any depth. */
	public const EXCLUDES = ['.git', '.github', '.DS_Store', 'node_modules', 'tests'];

	public function __construct(
		private ManifestValidator $validator,
	) {
	}

	/**
	 * @param string|null $outputDir where to write the zip; defaults to the extension's parent directory
	 *
	 * @throws InstallException
	 */
	public function package(string $extensionDir, ?string $outputDir = null, bool $force = false): PackageResult
	{
		$extensionDir = rtrim($extensionDir, '/');
		$real         = realpath($extensionDir);
		if ($real === false || !is_dir($real)) {
			throw InstallException::notFound("{$extensionDir} is not a directory.");
		}

		$manifest = $this->readManifest($real);
		$name     = explode('/', $manifest->id, 2)[1];

		// Keep the caller's spelling of the parent (a symlinked temp dir stays recognizable);
		// fall back to the resolved path when the input was relative.
		$parent    = str_starts_with($extensionDir, '/') ? dirname($extensionDir) : dirname($real);
		$outputDir = rtrim($outputDir ?? $parent, '/');
		if (!is_dir($outputDir)) {
			throw InstallException::notFound("Output directory {$outputDir} does not exist.");
		}
		$zipPath = $outputDir . '/' . $name . '-' . $manifest->version . '.zip';

		if (is_file($zipPath) && !$force) {
			throw InstallException::writeFailed("{$zipPath} already exists. Pass --force to overwrite it.");
		}

		$tmpPath = $zipPath . '.tmp-' . bin2hex(random_bytes(4));
		$skip    = [
			realpath($outputDir) . '/' . basename($zipPath),
			realpath($outputDir) . '/' . basename($tmpPath),
		];

		[$count, $hasAutoloader] = $this->build($real, $name, $zipPath, $tmpPath, $skip);

		return new PackageResult($zipPath, $count, $this->composerWarnings($real, $hasAutoloader));
	}

	/**
	 * Write the zip to $tmpPath, then move it to $zipPath.
	 *
	 * @param list<string> $skip absolute paths never to include
	 *
	 * @throws InstallException
	 *
	 * @return array{int,bool} file count and whether vendor/autoload.php was packed
	 */
	private function build(string $real, string $name, string $zipPath, string $tmpPath, array $skip): array
	{
		$zip    = new \ZipArchive();
		$opened = $zip->open($tmpPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
		if ($opened !== true) {
			throw InstallException::writeFailed("Could not create {$zipPath}.");
		}

		$count         = 0;
		$hasAutoloader = false;

		try {
			$zip->addEmptyDir($name);

			foreach ($this->walk($real, $skip) as $absolute => $relative) {
				if (is_dir($absolute)) {
					$zip->addEmptyDir($name . '/' . $relative);

					continue;
				}

				if (!$zip->addFile($absolute, $name . '/' . $relative)) {
					throw InstallException::writeFailed("Could not add {$relative} to the zip.");
				}
				$count++;
				$hasAutoloader = $hasAutoloader || $relative === 'vendor/autoload.php';
			}

			// addFile() is lazy: unreadable files and a full disk surface here.
			$status = $zip->getStatusString();
			if (!QuietCall::run(fn (): bool => $zip->close())) {
				throw InstallException::writeFailed("Could not write {$zipPath}: unreadable file or no space left ({$status}).");
			}

			if (!rename($tmpPath, $zipPath)) {
				throw InstallException::writeFailed("Could not move the finished zip into place at {$zipPath}.");
			}
		} catch (InstallException $e) {
			$this->discard($zip, $tmpPath);

			throw $e;
		} catch (\Throwable $e) {
			$this->discard($zip, $tmpPath);

			throw InstallException::writeFailed("Could not write {$zipPath}: " . $e->getMessage());
		}

		return [$count, $hasAutoloader];
	}

	/**
	 * @SuppressWarnings("PHPMD.EmptyCatchBlock") closing can fail again; only the unlink matters
	 */
	private function discard(\ZipArchive $zip, string $tmpPath): void
	{
		// Closing can fail again (that is why we are here), and a ZipArchive that already
		// failed to close throws ValueError on reuse; only the unlink matters.
		try {
			QuietCall::run(fn (): bool => $zip->close());
		} catch (\Throwable) {
		}
		if (is_file($tmpPath)) {
			QuietCall::run(fn (): bool => unlink($tmpPath));
		}
	}

	private function readManifest(string $dir): ExtensionManifest
	{
		$file = $dir . '/' . ExtensionArchive::MANIFEST;
		if (!is_file($file)) {
			throw InstallException::invalidManifest("{$dir} has no extension.json.");
		}

		$data = json_decode((string)file_get_contents($file), true);
		if (!is_array($data)) {
			throw InstallException::invalidManifest('extension.json is not valid JSON.');
		}

		$manifest = ExtensionManifest::fromArray($data);
		$error    = $this->validator->validate($manifest);
		if ($error !== null) {
			throw InstallException::invalidManifest($error);
		}

		return $manifest;
	}

	/**
	 * Every file and directory under $root, excluding EXCLUDES at any depth
	 * and the zip being written (and its temp file). Yields absolute path => relative path with
	 * forward slashes, directories first within each level (iterator order).
	 *
	 * @param list<string> $skip absolute paths never to include (the zip and its temp file)
	 *
	 * @return \Generator<string,string>
	 */
	private function walk(string $root, array $skip): \Generator
	{
		$filter = (static fn (\SplFileInfo $current): bool => !in_array($current->getFilename(), self::EXCLUDES, true));

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
				$filter,
			),
			\RecursiveIteratorIterator::SELF_FIRST,
		);

		foreach ($iterator as $item) {
			/** @var \SplFileInfo $item */
			$absolute = $item->getPathname();
			if (in_array($absolute, $skip, true)) {
				continue;
			}

			$relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($root) + 1));

			yield $absolute => $relative;
		}
	}

	/** @return list<string> */
	private function composerWarnings(string $dir, bool $hasAutoloader): array
	{
		$file = $dir . '/composer.json';
		if (!is_file($file) || $hasAutoloader) {
			return [];
		}

		$data = json_decode((string)file_get_contents($file), true);
		if (!is_array($data) || !is_array($data['require'] ?? null)) {
			return [];
		}

		$packages = array_filter(
			array_keys($data['require']),
			static fn (mixed $k): bool => is_string($k) && $k !== 'php' && !str_starts_with($k, 'ext-'),
		);
		if ($packages === []) {
			return [];
		}

		return [sprintf(
			'composer.json requires %s but there is no vendor/autoload.php — run `composer install --no-dev` first so the zip carries its dependencies.',
			implode(', ', $packages),
		)];
	}
}
