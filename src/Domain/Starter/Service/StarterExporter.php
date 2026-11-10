<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Starter\Service;

use TotalCMS\Domain\Starter\Data\StarterExportOptions;
use TotalCMS\Domain\Starter\Data\StarterExportResult;

/**
 * Writes a clean copy of the current project as a Composer starter: a
 * complete site that `composer create-project vendor/starter-name` installs.
 *
 * It only reads the running site. The copy leaves out what Composer and the
 * runtime regenerate (vendor, cache, logs), every credential (users, API
 * keys, OAuth, sessions, the site key), and the setup state, so the setup
 * wizard runs for whoever installs the starter. `.system` is copied by
 * allowlist, not denylist, so a runtime file added later stays out by
 * default. Then StarterProjectWriter writes the starter's own project files,
 * and a final scan fails the export if a known secret reached the output.
 */
final readonly class StarterExporter
{
	/** Top-level project entries never copied. tcms-data is copied on its own. */
	private const PROJECT_SKIP = ['vendor', 'cache', 'tmp', 'logs', 'composer.lock', '.git', 'tcms-data', self::IGNORE_FILE];

	/**
	 * Paths the author leaves out, one per line from the project root, with
	 * `*` wildcards that stay inside a folder: `snapshots/`, `bin/video-*.sh`.
	 * Lines starting with `#` are comments.
	 */
	private const IGNORE_FILE = '.starterignore';

	/** Names skipped at any depth. */
	private const ALWAYS_SKIP = ['.DS_Store', '__MACOSX', 'node_modules', '.cache'];

	/** The only `.system` entries a starter carries. */
	private const SYSTEM_KEEP = ['settings.json', 'extensions.json', 'extension-settings', 'access-groups.json', 'migrations.json'];

	/** Collections whose objects stay in a structure-only export. */
	private const STRUCTURE_COLLECTIONS = ['builder-pages'];

	private const LARGEST_FILES = 5;

	/** The Site SEO record, whose IndexNow key must not travel. */
	private const SEO_SITE_SCHEMA = 'seo-site';

	public function __construct(
		private StarterSecretScrubber $scrubber,
		private StarterProjectWriter $projectWriter,
	) {
	}

	public function export(StarterExportOptions $options, string $projectRoot, string $datadir): StarterExportResult
	{
		$projectRoot = rtrim($projectRoot, '/');
		$datadir     = rtrim($datadir, '/');
		$dest        = $this->prepareDestination($options, $projectRoot);
		$result      = new StarterExportResult($dest);

		$this->copyTree($projectRoot, $dest, $projectRoot, $datadir, $this->ignorePatterns($projectRoot));
		$this->copyData($datadir, $dest . '/tcms-data', $projectRoot, $options, $result);
		$this->projectWriter->write($options, $projectRoot, $dest, $result);

		$this->measure($dest, $result);
		$result->secretHits = $this->scan($dest, $this->scrubber->knownSecrets($datadir));

		return $result;
	}

	/**
	 * Collections a structure-only export would empty, with their object
	 * counts, so the command can say what the content prompt is about.
	 *
	 * @return array<string,int>
	 */
	public function contentSummary(string $datadir): array
	{
		$summary = [];
		foreach ($this->collections($datadir) as $id => $meta) {
			if (!in_array($id, self::STRUCTURE_COLLECTIONS, true) && ($meta['schema'] ?? '') !== 'auth') {
				$summary[$id] = count($this->objectEntries($datadir . '/' . $id));
			}
		}

		return array_filter($summary);
	}

	private function prepareDestination(StarterExportOptions $options, string $projectRoot): string
	{
		$dest = rtrim($options->destination, '/');
		if ($dest === '') {
			throw new \InvalidArgumentException('Give a folder to export the starter to.');
		}

		$existed = is_dir($dest);
		if ($existed && !$options->force && $this->entries($dest) !== []) {
			throw new \InvalidArgumentException("{$dest} is not empty. Use --force to write into it anyway.");
		}

		$this->makeDir($dest);

		// Compared once both exist, so symlinks (macOS's /tmp) resolve the same way on both sides.
		$real = (string)realpath($dest);
		$root = (string)realpath($projectRoot);
		if ($real === $root || str_starts_with($real . '/', $root . '/')) {
			if (!$existed) {
				rmdir($dest);
			}

			throw new \InvalidArgumentException('The starter must be exported outside the project it is copied from.');
		}

		return $real;
	}

	/**
	 * The project's .starterignore patterns, without comments, blank lines
	 * or the slashes that only mark a folder.
	 *
	 * @return list<string>
	 */
	private function ignorePatterns(string $projectRoot): array
	{
		$file = $projectRoot . '/' . self::IGNORE_FILE;
		if (!is_file($file)) {
			return [];
		}

		$patterns = [];
		foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
			$pattern = trim(trim($line), '/');
			if ($pattern !== '' && !str_starts_with($pattern, '#')) {
				$patterns[] = $pattern;
			}
		}

		return $patterns;
	}

	/** @param list<string> $ignore */
	private function isIgnored(string $relative, array $ignore): bool
	{
		foreach ($ignore as $pattern) {
			if (fnmatch($pattern, $relative, FNM_PATHNAME | FNM_PERIOD)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Copy the project, leaving out what PROJECT_SKIP, ALWAYS_SKIP and the
	 * project's .starterignore name, and the data folder.
	 *
	 * @param list<string> $ignore
	 */
	private function copyTree(string $from, string $to, string $projectRoot, string $datadir, array $ignore = []): void
	{
		foreach ($this->entries($from) as $name) {
			$path = $from . '/' . $name;
			if (in_array($name, self::ALWAYS_SKIP, true) || $path === $datadir || $this->isWriteLeftover($name)) {
				continue;
			}
			if ($from === $projectRoot && in_array($name, self::PROJECT_SKIP, true)) {
				continue;
			}
			if ($projectRoot !== '' && $this->isIgnored(substr($path, strlen($projectRoot) + 1), $ignore)) {
				continue;
			}
			// Installed from the totalcms/cms package on every composer install.
			if ($path === $projectRoot . '/.claude/skills/totalcms') {
				continue;
			}

			$this->copyEntry($path, $to . '/' . $name, fn (string $f, string $t) => $this->copyTree($f, $t, $projectRoot, $datadir, $ignore));
		}
	}

	private function copyData(string $datadir, string $to, string $projectRoot, StarterExportOptions $options, StarterExportResult $result): void
	{
		// Made first: the data folder's own files (its .htaccess) sort ahead
		// of every folder that would otherwise create it.
		$this->makeDir($to);
		foreach ($this->entries($datadir) as $name) {
			$path = $datadir . '/' . $name;
			if (in_array($name, self::ALWAYS_SKIP, true)) {
				continue;
			}

			if ($name === '.system') {
				$this->copySystem($path, $to . '/.system', $datadir, $projectRoot, $result);
				continue;
			}

			$meta = $this->readJson($path . '/.meta.json');
			if ($meta !== [] && $this->emptiesCollection($name, $meta, $options)) {
				$this->copyEmptyCollection($path, $to . '/' . $name, $meta);
				$result->emptiedCollections[] = $name;
				continue;
			}

			$this->copyEntry($path, $to . '/' . $name, fn (string $f, string $t) => $this->copyTree($f, $t, '', ''));

			if (($meta['schema'] ?? '') === self::SEO_SITE_SCHEMA) {
				$this->resetIndexNow($to . '/' . $name, $result);
			}
		}
	}

	/**
	 * IndexNow off, and no key, in the copied Site SEO record. Every site
	 * built from the starter would otherwise share the source site's key,
	 * and one with IndexNow left on would submit its URLs the moment it
	 * saved a page. Turning it on later makes the site its own key.
	 */
	private function resetIndexNow(string $collectionDir, StarterExportResult $result): void
	{
		foreach ($this->objectEntries($collectionDir) as $name) {
			$path   = $collectionDir . '/' . $name;
			$record = $this->readJson($path);
			if (($record['indexNowKey'] ?? '') === '' && !($record['indexNowEnabled'] ?? false)) {
				continue;
			}

			$record['indexNowEnabled'] = false;
			$record['indexNowKey']     = '';
			$this->writeJson($path, $record);
			$result->scrubbedSettings[] = basename($collectionDir) . '/' . $name . ': indexNowKey';
		}
	}

	/**
	 * Users never travel. Without content, nor does anything else but the
	 * pages: the schemas, collection settings and templates do.
	 *
	 * @param array<string,mixed> $meta
	 */
	private function emptiesCollection(string $id, array $meta, StarterExportOptions $options): bool
	{
		if (($meta['schema'] ?? '') === 'auth') {
			return true;
		}

		return !$options->includeContent && !in_array($id, self::STRUCTURE_COLLECTIONS, true);
	}

	/**
	 * The collection's dot files (meta, an empty index, ordering) without its
	 * objects or their media, with the counters reset so ids start over.
	 *
	 * @param array<string,mixed> $meta
	 */
	private function copyEmptyCollection(string $from, string $to, array $meta): void
	{
		$this->makeDir($to);
		foreach ($this->entries($from) as $name) {
			if (str_starts_with($name, '.') && is_file($from . '/' . $name) && $name !== '.meta.json' && $name !== '.index.json' && !$this->isWriteLeftover($name)) {
				copy($from . '/' . $name, $to . '/' . $name);
			}
		}

		$meta['count']        = 0;
		$meta['totalObjects'] = 0;
		unset($meta['lastUpdated']);
		$this->writeJson($to . '/.meta.json', $meta);
		$this->writeJson($to . '/.index.json', ['objects' => []]);
	}

	private function copySystem(string $from, string $to, string $datadir, string $projectRoot, StarterExportResult $result): void
	{
		$this->makeDir($to);
		foreach ($this->entries($from) as $name) {
			$path = $from . '/' . $name;

			if (preg_match('/^settings(-[A-Za-z0-9_-]+)?\.json$/', $name) === 1) {
				$scrubbed = [];
				$this->writeJson($to . '/' . $name, $this->scrubber->scrubSettings($this->readJson($path), $projectRoot, $scrubbed));
				array_push($result->scrubbedSettings, ...array_map(static fn (string $k): string => "{$name}: {$k}", $scrubbed));
			} elseif ($name === 'extension-settings') {
				$this->copyExtensionSettings($path, $to . '/' . $name, $datadir, $projectRoot, $result);
			} elseif (in_array($name, self::SYSTEM_KEEP, true)) {
				copy($path, $to . '/' . $name);
			}
		}
	}

	private function copyExtensionSettings(string $from, string $to, string $datadir, string $projectRoot, StarterExportResult $result): void
	{
		foreach (glob($from . '/*/*.json') ?: [] as $file) {
			$extensionId = basename(dirname($file)) . '/' . basename($file, '.json');
			$scrubbed    = [];
			$settings    = $this->scrubber->scrubExtensionSettings($this->readJson($file), $extensionId, $datadir, $projectRoot, $scrubbed);

			$this->makeDir($to . '/' . dirname($extensionId));
			$this->writeJson($to . '/' . $extensionId . '.json', $settings);
			array_push($result->scrubbedSettings, ...array_map(static fn (string $k): string => "{$extensionId}: {$k}", $scrubbed));
		}
	}

	/**
	 * Files in the output that contain a known secret, relative to it.
	 *
	 * @param list<string> $secrets
	 *
	 * @return list<string>
	 */
	private function scan(string $dest, array $secrets): array
	{
		if ($secrets === []) {
			return [];
		}

		$hits = [];
		foreach ($this->files($dest) as $file) {
			$content = (string)file_get_contents($file);
			foreach ($secrets as $secret) {
				if (str_contains($content, $secret)) {
					$hits[] = substr($file, strlen($dest) + 1);
					break;
				}
			}
		}

		return $hits;
	}

	private function measure(string $dest, StarterExportResult $result): void
	{
		$sizes = [];
		foreach ($this->files($dest) as $file) {
			$sizes[substr($file, strlen($dest) + 1)] = (int)filesize($file);
		}

		arsort($sizes);
		$result->files        = count($sizes);
		$result->bytes        = array_sum($sizes);
		$result->largestFiles = array_slice($sizes, 0, self::LARGEST_FILES, true);
	}

	/**
	 * Every file under $dir, in a stable order.
	 *
	 * @return list<string>
	 */
	private function files(string $dir): array
	{
		$files = [];
		foreach ($this->entries($dir) as $name) {
			$path = $dir . '/' . $name;
			if (is_dir($path) && !is_link($path)) {
				array_push($files, ...$this->files($path));
			} elseif (is_file($path)) {
				$files[] = $path;
			}
		}

		return $files;
	}

	/** @param callable(string,string):void $recurse */
	private function copyEntry(string $from, string $to, callable $recurse): void
	{
		if (is_link($from)) {
			return;
		}

		if (is_dir($from)) {
			$this->makeDir($to);
			$recurse($from, $to);

			return;
		}

		copy($from, $to);
	}

	/**
	 * Collection folders in the data dir, by id, with their meta.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function collections(string $datadir): array
	{
		$collections = [];
		foreach ($this->entries($datadir) as $name) {
			$meta = $this->readJson($datadir . '/' . $name . '/.meta.json');
			if ($meta !== []) {
				$collections[$name] = $meta;
			}
		}

		return $collections;
	}

	/**
	 * An object is a file or a media folder that is not a dot entry.
	 *
	 * @return list<string>
	 */
	private function objectEntries(string $collectionDir): array
	{
		return array_values(array_filter(
			$this->entries($collectionDir),
			static fn (string $name): bool => !str_starts_with($name, '.') && (str_ends_with($name, '.json') || str_ends_with($name, '.md')),
		));
	}

	/**
	 * A directory's entries, sorted so the output is the same on every run
	 * and on every filesystem.
	 *
	 * @return list<string>
	 */
	private function entries(string $dir): array
	{
		$entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
		sort($entries, SORT_STRING);

		return $entries;
	}

	/**
	 * A lock file beside a file that saves read and rewrite (.meta.json.lock),
	 * or the temp of a write that was killed before its rename
	 * (.cake.json.tmp.1a2b3c4d) — runtime leftovers, and a temp can hold a
	 * user record.
	 */
	private function isWriteLeftover(string $name): bool
	{
		return str_starts_with($name, '.')
			&& (str_ends_with($name, '.lock') || preg_match('/\.tmp\.[0-9a-f]{8}$/', $name) === 1);
	}

	private function makeDir(string $dir): void
	{
		if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
			throw new \RuntimeException("Could not create {$dir}.");
		}
	}

	/** @return array<string,mixed> */
	private function readJson(string $path): array
	{
		if (!is_file($path)) {
			return [];
		}

		$decoded = json_decode((string)file_get_contents($path), true);

		return is_array($decoded) ? $decoded : [];
	}

	/** @param array<mixed> $data */
	private function writeJson(string $path, array $data): void
	{
		file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
	}
}
