<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Skill\Service;

use TotalCMS\Infrastructure\Filesystem\FileUtils;
use TotalCMS\Support\Version;

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
 *
 * Installed copies are stamped with a fingerprint of the *source* files (sidecar
 * `.skill-manifest.json` plus a `metadata:` block in SKILL.md's frontmatter) so
 * `check()` can tell an agent whether the copy it just loaded is still current.
 * The fingerprint is content-based, not version-based: the skill text changes far
 * less often than the CMS release number, and it must match across both layouts,
 * so it is computed before the zip rewrite.
 */
class SkillInstaller
{
	/** Marks a line whose Composer paths must survive the zip rewrite. */
	public const KEEP_MARKER = '<!-- composer-paths -->';

	/** Sidecar file holding the installed fingerprint, written into the target. */
	public const MANIFEST = '.skill-manifest.json';

	/** Composer-layout path forms rewritten for zip installs, in order. */
	private const ZIP_REWRITES = [
		'vendor/bin/tcms'       => 'php resources/bin/tcms',
		'vendor/totalcms/cms/'  => '',
	];

	/**
	 * Copy the skill tree from $source into $target.
	 *
	 * @param bool                $composerInstall False rewrites Composer paths in `.md` files for the zip layout.
	 * @param array<string,mixed> $stampExtra      Extra keys for the sidecar manifest — an extension skill records its owner here
	 *
	 * @return array{installed: bool, source: string, target: string, copied: list<string>, failed: list<string>, hash: string}
	 */
	public function install(string $source, string $target, bool $force = true, bool $composerInstall = true, array $stampExtra = []): array
	{
		$source = rtrim($source, '/');
		$target = rtrim($target, '/');

		$result = [
			'installed' => false,
			'source'    => $source,
			'target'    => $target,
			'copied'    => [],
			'failed'    => [],
			'hash'      => '',
		];

		if (!is_dir($source)) {
			return $result;
		}

		['copied' => $copied, 'failed' => $failed] = FileUtils::copyTree(
			$source,
			$target,
			$force,
			fn (string $from, string $to): bool => $this->copyFile($from, $to, $composerInstall),
		);

		$fingerprint = $this->fingerprint($source);

		$result['installed'] = true;
		$result['copied']    = $copied;
		$result['failed']    = $failed;
		$result['hash']      = $fingerprint['hash'];

		$this->stamp($target, $fingerprint, $composerInstall, $stampExtra);

		return $result;
	}

	/**
	 * Fingerprint a skill source tree: a sha256 per file plus one hash over them all.
	 *
	 * Computed on the source files, before any zip rewrite, so the same skill
	 * fingerprints identically whichever layout it is installed into.
	 *
	 * @return array{hash: string, files: array<string,string>}
	 */
	public function fingerprint(string $source): array
	{
		$source = rtrim($source, '/');
		$files  = [];

		if (!is_dir($source)) {
			return ['hash' => '', 'files' => []];
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST,
		);

		foreach ($iterator as $item) {
			if (!$item instanceof \SplFileInfo || !$item->isFile()) {
				continue;
			}

			$relative = str_replace(
				DIRECTORY_SEPARATOR,
				'/',
				ltrim(str_replace($source, '', $item->getPathname()), DIRECTORY_SEPARATOR),
			);

			if ($relative === self::MANIFEST) {
				continue;
			}

			$hash = @hash_file('sha256', $item->getPathname());
			if ($hash === false) {
				continue;
			}

			$files[$relative] = $hash;
		}

		ksort($files);

		$lines = '';
		foreach ($files as $relative => $hash) {
			$lines .= $relative . "\n" . $hash . "\n";
		}

		return ['hash' => hash('sha256', $lines), 'files' => $files];
	}

	/**
	 * Compare an installed copy against the current source.
	 *
	 * Reads the installed fingerprint from the sidecar manifest, falling back to
	 * the `skill-hash` stamped into SKILL.md's frontmatter (with no per-file
	 * detail) when the sidecar is gone. Writes nothing.
	 *
	 * @return array{installed: bool, current: bool, hash: string, installedHash: ?string, installedFor: ?string, changed: list<string>, added: list<string>, removed: list<string>}
	 */
	public function check(string $source, string $target): array
	{
		$target      = rtrim($target, '/');
		$fingerprint = $this->fingerprint($source);
		$installed   = $this->readStamp($target);

		$result = [
			'installed'     => $installed !== null,
			'current'       => false,
			'hash'          => $fingerprint['hash'],
			'installedHash' => $installed['hash'] ?? null,
			'installedFor'  => $installed['installedFor'] ?? null,
			'changed'       => [],
			'added'         => [],
			'removed'       => [],
		];

		if ($installed === null) {
			return $result;
		}

		$result['current'] = $fingerprint['hash'] !== '' && $installed['hash'] === $fingerprint['hash'];

		foreach ($fingerprint['files'] as $relative => $hash) {
			if (!isset($installed['files'][$relative])) {
				$result['added'][] = $relative;
			} elseif ($installed['files'][$relative] !== $hash) {
				$result['changed'][] = $relative;
			}
		}

		foreach (array_keys($installed['files']) as $relative) {
			if (!isset($fingerprint['files'][$relative])) {
				$result['removed'][] = $relative;
			}
		}

		return $result;
	}

	/**
	 * Write the fingerprint into the installed copy: sidecar manifest + frontmatter.
	 *
	 * Runs after the zip rewrite so the stamp survives it.
	 *
	 * @param array{hash: string, files: array<string,string>} $fingerprint
	 * @param array<string,mixed>                              $extra
	 */
	private function stamp(string $target, array $fingerprint, bool $composerInstall, array $extra = []): void
	{
		$layout  = $composerInstall ? 'composer' : 'zip';
		$version = Version::number();

		$manifest = $extra + [
			'hash'         => $fingerprint['hash'],
			'files'        => $fingerprint['files'],
			'installedFor' => $version,
			'layout'       => $layout,
			'installedAt'  => date('c'),
		];

		@file_put_contents(
			$target . '/' . self::MANIFEST,
			json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
		);

		$skill    = $target . '/SKILL.md';
		$contents = @file_get_contents($skill);
		if ($contents === false) {
			return;
		}

		$stamped = $this->stampFrontmatter($contents, $fingerprint['hash'], $version, $layout);
		if ($stamped !== null) {
			@file_put_contents($skill, $stamped);
		}
	}

	/**
	 * Insert (or refresh) the `metadata:` block in SKILL.md's frontmatter, right
	 * after `description:`. Returns null when the file has no frontmatter.
	 */
	private function stampFrontmatter(string $contents, string $hash, string $version, string $layout): ?string
	{
		$lines = explode("\n", $contents);

		if ($lines[0] !== '---') {
			return null;
		}

		$close = null;
		for ($i = 1, $count = count($lines); $i < $count; $i++) {
			if ($lines[$i] === '---') {
				$close = $i;
				break;
			}
		}

		if ($close === null) {
			return null;
		}

		$front = array_slice($lines, 1, $close - 1);
		$front = $this->withoutMetadata($front);

		$block = [
			'metadata:',
			'  skill-hash: ' . $hash,
			'  installed-for: ' . $version,
			'  layout: ' . $layout,
		];

		$insert = count($front);
		foreach ($front as $index => $line) {
			if (str_starts_with($line, 'description:')) {
				$insert = $index + 1;
				// Skip any wrapped continuation lines belonging to description.
				while (isset($front[$insert]) && trim($front[$insert]) !== '' && preg_match('/^\s/', $front[$insert]) === 1) {
					$insert++;
				}
				break;
			}
		}

		array_splice($front, $insert, 0, $block);

		return implode("\n", array_merge(['---'], $front, array_slice($lines, $close)));
	}

	/**
	 * Drop an existing top-level `metadata:` key (and its indented children) so a
	 * re-install refreshes the stamp rather than stacking a second one.
	 *
	 * @param list<string> $front
	 *
	 * @return list<string>
	 */
	private function withoutMetadata(array $front): array
	{
		$kept     = [];
		$dropping = false;

		foreach ($front as $line) {
			if ($dropping) {
				if (preg_match('/^\s/', $line) === 1 || trim($line) === '') {
					continue;
				}
				$dropping = false;
			}

			if (str_starts_with($line, 'metadata:')) {
				$dropping = true;
				continue;
			}

			$kept[] = $line;
		}

		return $kept;
	}

	/**
	 * Read the fingerprint stamped into an installed copy.
	 *
	 * @return array{hash: string, files: array<string,string>, installedFor: ?string}|null
	 */
	private function readStamp(string $target): ?array
	{
		$manifest = $target . '/' . self::MANIFEST;

		if (is_file($manifest)) {
			$decoded = json_decode((string)@file_get_contents($manifest), true);

			if (is_array($decoded) && isset($decoded['hash']) && is_string($decoded['hash'])) {
				$files = [];
				if (isset($decoded['files']) && is_array($decoded['files'])) {
					foreach ($decoded['files'] as $relative => $hash) {
						if (is_string($relative) && is_string($hash)) {
							$files[$relative] = $hash;
						}
					}
				}

				return [
					'hash'         => $decoded['hash'],
					'files'        => $files,
					'installedFor' => isset($decoded['installedFor']) && is_string($decoded['installedFor'])
						? $decoded['installedFor']
						: null,
				];
			}
		}

		$skill = $target . '/SKILL.md';
		if (!is_file($skill)) {
			return null;
		}

		$contents = (string)@file_get_contents($skill);
		if (preg_match('/^\s*skill-hash:\s*(\S+)\s*$/m', $contents, $match) !== 1) {
			return null;
		}

		$version = preg_match('/^\s*installed-for:\s*(\S+)\s*$/m', $contents, $found) === 1 ? $found[1] : null;

		return ['hash' => $match[1], 'files' => [], 'installedFor' => $version];
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
