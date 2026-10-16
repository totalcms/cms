<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service\Install;

use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\Service\ManifestValidator;

/**
 * Reads an operator-supplied extension zip.
 *
 * Nothing here trusts the archive. Every entry name is checked before any
 * byte is written, and extraction streams entry by entry into a path WE
 * build — `ZipArchive::extractTo()` is never used, so a crafted name cannot
 * choose where it lands. The manifest must sit at the zip root or inside
 * exactly one top-level folder (the shape `tcms extension:package` writes
 * and the shape Finder produces when a folder is compressed).
 *
 * Finder adds `__MACOSX/` and `.DS_Store` entries; both are ignored
 * throughout so a right-click → Compress zip installs cleanly.
 */
final class ExtensionArchive
{
	public const MANIFEST = 'extension.json';

	/** Sanity caps — a real extension is nowhere near either. */
	public const MAX_ENTRIES            = 20_000;
	public const MAX_UNCOMPRESSED_BYTES = 200 * 1024 * 1024;

	public function __construct(
		private readonly ManifestValidator $validator,
	) {
	}

	/**
	 * Resource-fork and desktop-metadata entries that macOS adds to zips.
	 */
	public static function isIgnored(string $entryName): bool
	{
		if (str_starts_with($entryName, '__MACOSX/')) {
			return true;
		}

		$base = basename(rtrim($entryName, '/'));

		return $base === '.DS_Store';
	}

	/**
	 * Validate the archive and read its manifest without extracting anything.
	 *
	 * @throws InstallException
	 */
	public function inspect(string $zipPath): InstallPlan
	{
		$zip = new \ZipArchive();
		if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) {
			throw InstallException::invalidArchive('The uploaded file is not a zip archive, or it is damaged.');
		}

		try {
			if ($zip->numFiles > self::MAX_ENTRIES) {
				throw InstallException::invalidArchive(sprintf('The archive has %d entries; the limit is %d.', $zip->numFiles, self::MAX_ENTRIES));
			}

			$names = [];
			$bytes = 0;
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$stat = $zip->statIndex($i);
				if ($stat === false) {
					throw InstallException::invalidArchive('The archive is damaged: an entry could not be read.');
				}

				$name = (string)$stat['name'];
				$this->assertSafeName($name);
				$this->assertNotSymlink($zip, $i, $name);

				if (self::isIgnored($name)) {
					continue;
				}

				$bytes += (int)$stat['size'];
				if ($bytes > self::MAX_UNCOMPRESSED_BYTES) {
					throw InstallException::invalidArchive('The archive unpacks to more than 200 MB; refusing to install it.');
				}

				$names[] = $name;
			}

			$prefix   = $this->locateManifestPrefix($names);
			$json     = $zip->getFromName($prefix . self::MANIFEST);
			$manifest = $this->parseManifest($json === false ? '' : $json);

			$fileCount = count(array_filter($names, static fn (string $n): bool => !str_ends_with($n, '/')));
			$warnings  = $this->composerWarnings($zip, $prefix, $names);

			return new InstallPlan($manifest, $prefix, $fileCount, $bytes, $warnings);
		} finally {
			$zip->close();
		}
	}

	/**
	 * Stream every non-ignored entry into $destination with $plan->prefix
	 * stripped. $destination must not exist yet; it is created 0755.
	 *
	 * @throws InstallException
	 */
	public function extractTo(string $zipPath, InstallPlan $plan, string $destination): void
	{
		if (!is_dir($destination) && !mkdir($destination, 0755, true)) {
			throw InstallException::writeFailed("Could not create {$destination}.");
		}

		$zip = new \ZipArchive();
		if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) {
			throw InstallException::invalidArchive('The uploaded file is not a zip archive, or it is damaged.');
		}

		try {
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$name = (string)$zip->getNameIndex($i);
				if (self::isIgnored($name)) {
					continue;
				}

				// inspect() already validated every name; validate again so this
				// method is safe to call on its own.
				$this->assertSafeName($name);

				$relative = $this->relativeName($name, $plan);
				if ($relative === '') {
					continue; // the wrapper directory entry itself
				}
				$this->assertSafeName($relative);

				$this->extractEntry($zip, $name, $destination . '/' . $relative);
			}
		} finally {
			$zip->close();
		}
	}

	/**
	 * Entry name with the plan's wrapper folder stripped.
	 *
	 * @throws InstallException
	 */
	private function relativeName(string $name, InstallPlan $plan): string
	{
		if ($plan->prefix === '') {
			return $name;
		}
		if (!str_starts_with($name, $plan->prefix)) {
			throw InstallException::invalidArchive("The archive entry {$name} is outside the expected folder {$plan->prefix}.");
		}

		return substr($name, strlen($plan->prefix));
	}

	/**
	 * Create the directory, or stream the file, for one archive entry.
	 *
	 * @throws InstallException
	 */
	private function extractEntry(\ZipArchive $zip, string $name, string $target): void
	{
		if (str_ends_with($name, '/')) {
			$this->makeDir($target);

			return;
		}

		$this->makeDir(dirname($target));

		$stream = $zip->getStream($name);
		if ($stream === false) {
			throw InstallException::invalidArchive("The archive is damaged: {$name} could not be read.");
		}

		$written = file_put_contents($target, $stream);
		fclose($stream);
		if ($written === false) {
			throw InstallException::writeFailed("Could not write {$target} — is the disk full?");
		}
		chmod($target, 0644);
	}

	/**
	 * @throws InstallException
	 */
	private function makeDir(string $dir): void
	{
		if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
			throw InstallException::writeFailed("Could not create {$dir}.");
		}
	}

	/**
	 * Reject names that could escape the destination. Zip names use `/`;
	 * a backslash is never legitimate and would be a traversal on Windows.
	 */
	private function assertSafeName(string $name): void
	{
		if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
			throw InstallException::invalidArchive("The archive contains an unsafe path: {$name}");
		}

		foreach (explode('/', $name) as $segment) {
			if ($segment === '..') {
				throw InstallException::invalidArchive("The archive contains an unsafe path: {$name}");
			}
		}
	}

	/**
	 * libzip stores the unix mode in the high 16 bits of the external
	 * attributes; S_IFLNK there means the entry is a symlink. A symlink inside
	 * an extension could point anywhere on the server once extracted.
	 */
	private function assertNotSymlink(\ZipArchive $zip, int $index, string $name): void
	{
		$opsys = 0;
		$attrs = 0;
		if (!$zip->getExternalAttributesIndex($index, $opsys, $attrs)) {
			return;
		}

		if ($opsys === \ZipArchive::OPSYS_UNIX && ((($attrs >> 16) & 0170000) === 0120000)) {
			throw InstallException::invalidArchive("The archive contains a symlink ({$name}); symlinks are not allowed.");
		}
	}

	/**
	 * Find extension.json at the root or inside exactly one top-level folder,
	 * and in the folder case check that every other entry lives under it.
	 *
	 * @param list<string> $names non-ignored entry names
	 *
	 * @return string the folder prefix ending in `/`, or `''`
	 */
	private function locateManifestPrefix(array $names): string
	{
		$candidates = [];
		foreach ($names as $name) {
			if ($name === self::MANIFEST) {
				$candidates[] = '';
			} elseif (preg_match('#^([^/]+)/' . preg_quote(self::MANIFEST, '#') . '$#', $name, $m) === 1) {
				$candidates[] = $m[1] . '/';
			}
		}

		if ($candidates === []) {
			throw InstallException::invalidArchive('The archive does not contain an extension.json at its root or inside a single top-level folder.');
		}

		if (count($candidates) > 1) {
			throw InstallException::invalidArchive('The archive contains more than one extension.json; upload one extension at a time.');
		}

		$prefix = $candidates[0];
		if ($prefix === '') {
			return '';
		}

		foreach ($names as $name) {
			if (!str_starts_with($name, $prefix)) {
				throw InstallException::invalidArchive("The archive must contain a single folder ({$prefix}) with the extension inside it; found {$name} beside it.");
			}
		}

		return $prefix;
	}

	private function parseManifest(string $json): ExtensionManifest
	{
		$data = json_decode($json, true);
		if (!is_array($data)) {
			throw InstallException::invalidManifest('extension.json is not valid JSON.');
		}

		// fromArray() casts with (string); an array there is a warning, not an
		// exception, and would yield the id "Array". Refuse it up front.
		foreach (['id', 'name', 'description', 'version', 'entrypoint', 'settings_schema', 'min_edition', 'license', 'icon', 'reviewNote'] as $key) {
			if (isset($data[$key]) && !is_scalar($data[$key])) {
				throw InstallException::invalidManifest("extension.json has a malformed field: {$key} must be a string.");
			}
		}

		try {
			$manifest = ExtensionManifest::fromArray($data);
		} catch (\Throwable $e) {
			throw InstallException::invalidManifest('extension.json has a malformed field: ' . $e->getMessage());
		}

		$error = $this->validator->validate($manifest);
		if ($error !== null) {
			throw InstallException::invalidManifest($error);
		}

		$reasons = $this->validator->getIncompatibilityReasons($manifest);
		if ($reasons !== []) {
			throw InstallException::incompatible(implode('; ', $reasons));
		}

		return $manifest;
	}

	/**
	 * An extension with Composer dependencies loads them through its own
	 * `vendor/autoload.php` (see ExtensionEntrypointLoader). A zip that
	 * carries a composer.json with real requires but no vendor/ will not work,
	 * and the developer is the one who can fix that — so we say so, loudly,
	 * rather than refuse.
	 *
	 * @param list<string> $names
	 *
	 * @return list<string>
	 */
	private function composerWarnings(\ZipArchive $zip, string $prefix, array $names): array
	{
		$composer = $zip->getFromName($prefix . 'composer.json');
		if ($composer === false) {
			return [];
		}

		$data = json_decode($composer, true);
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

		if (in_array($prefix . 'vendor/autoload.php', $names, true)) {
			return [];
		}

		return [sprintf(
			'composer.json requires %s but the archive has no vendor/autoload.php — the extension will not load until its dependencies are included. Run `composer install --no-dev` in the extension and package it again.',
			implode(', ', $packages),
		)];
	}
}
