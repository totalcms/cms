<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Builder\Data\StarterManifest;
use TotalCMS\Domain\JumpStart\Service\JumpStartImporter;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Domain\Template\Service\TemplateMigrationService;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\OperationResult;
use TotalCMS\Support\PathResolver;

readonly class StarterService
{
	private LoggerInterface $logger;

	public function __construct(
		private BuilderConfigService $builderConfig,
		private BuilderInstaller $builderInstaller,
		private TemplateLister $templateLister,
		private TemplateMigrationService $templateMigration,
		private JumpStartImporter $jumpStartImporter,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::Builder);
	}

	/**
	 * List available starter templates.
	 *
	 * @return list<StarterManifest>
	 */
	public function listStarters(): array
	{
		$startersDir = $this->startersDir();
		if (!is_dir($startersDir)) {
			return [];
		}

		$entries = scandir($startersDir);
		if ($entries === false) {
			return [];
		}

		$starters = [];
		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$manifest = $this->readManifest($this->startersDir() . '/' . $entry);
			if ($manifest instanceof StarterManifest) {
				$starters[] = $manifest;
			}
		}

		return $starters;
	}

	/**
	 * Scaffold a new site from a starter template. Copies template + asset
	 * files, ensures the pages collection exists, then imports the starter's
	 * `jumpstart.json` to seed pages and any demo content. Starters that don't
	 * ship a jumpstart.json install templates only.
	 */
	public function scaffold(string $starterName, bool $force = false): OperationResult
	{
		$manifest = $this->loadManifest($starterName);
		if (!$manifest instanceof StarterManifest) {
			return OperationResult::failure("Starter '{$starterName}' not found");
		}

		// Check if page templates already exist. Recursive, or a site that nests
		// its pages (pages/blog/index.twig) looks empty to this guard and gets
		// scaffolded over.
		//
		// Still scoped to pages/ rather than the whole tree: the built-in
		// defaults layer ships layouts/default.twig, so a whole-tree check is
		// non-empty on a fresh install and would refuse every first scaffold.
		if (!$force && $this->templateLister->listBuilderTemplates('pages', true) !== []) {
			return OperationResult::failure('Templates already exist. Use --force to overwrite.');
		}

		// Copy template files
		$copied = $this->copyTemplateFiles($manifest->directory, $force);

		// Copy starter assets (CSS, JS, images) into the docroot's assets dir.
		// Done separately from template copy because the destination is the
		// public docroot, not tcms-data/builder/.
		$assetsCopied = $this->copyAssetFiles($manifest->directory, $force);

		// Create builder-pages collection if it doesn't exist. Must run before
		// the jumpstart import so the page objects have a collection to land in.
		$this->builderInstaller->ensurePagesCollection();

		// Import the starter's jumpstart.json — pages + any demo content. Page
		// order in the admin sidebar follows the order objects appear in the
		// jumpstart file: BuilderOrderService's auto-migration reads the
		// collection index in insertion order on first access.
		$jumpStart = $this->importJumpStart($manifest);

		$this->logger->info('Starter scaffolded', [
			'starter'    => $starterName,
			'files'      => $copied,
			'assetFiles' => $assetsCopied,
			'jumpStart'  => $jumpStart !== null,
		]);

		$assetsNote = $assetsCopied > 0 ? ", {$assetsCopied} asset(s) copied" : '';
		$message    = "Scaffolded '{$manifest->name}' starter: {$copied} files copied{$assetsNote}";
		if ($jumpStart !== null) {
			$message .= $jumpStart['ok']
				? ', jumpstart imported'
				: ', jumpstart import failed (' . ($jumpStart['error'] ?? 'unknown') . ')';
		}

		return OperationResult::success($message, [
			'starter'      => $starterName,
			'filesCopied'  => $copied,
			'assetsCopied' => $assetsCopied,
			'jumpStart'    => $jumpStart,
		]);
	}

	/**
	 * Resolve a user-supplied starter name to its manifest. Lookup is
	 * case-insensitive and matches against both the folder name and the
	 * manifest's display `name`, so the title-case labels shown by
	 * `builder:init --list` (e.g. "Blog") work as input alongside the
	 * lowercase folder names (e.g. "blog"). Returns null if no starter
	 * matches.
	 */
	private function loadManifest(string $starterName): ?StarterManifest
	{
		$dir = $this->resolveStarterDir($starterName);

		return $dir === null ? null : $this->readManifest($dir);
	}

	/**
	 * Resolve a starter name to its directory path. Tries an exact folder
	 * match first (the common case — `listStarters()` passes real folder
	 * names), then falls back to a case-insensitive match against each
	 * starter's folder name and manifest `name`.
	 */
	private function resolveStarterDir(string $starterName): ?string
	{
		$base = $this->startersDir();
		if (!is_dir($base)) {
			return null;
		}

		// Exact folder match — cheap, covers the canonical lowercase input.
		$exact = $base . '/' . $starterName;
		if (is_dir($exact)) {
			return $exact;
		}

		$entries = scandir($base);
		if ($entries === false) {
			return null;
		}

		$needle = strtolower($starterName);
		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$dir = $base . '/' . $entry;
			if (!is_dir($dir)) {
				continue;
			}
			if (strtolower($entry) === $needle) {
				return $dir;
			}
			$manifest = $this->readManifest($dir);
			if ($manifest instanceof StarterManifest && strtolower($manifest->name) === $needle) {
				return $dir;
			}
		}

		return null;
	}

	/**
	 * Read and parse the manifest.json inside a known starter directory.
	 * Returns null if the manifest is missing or invalid.
	 */
	private function readManifest(string $starterDir): ?StarterManifest
	{
		$manifestPath = $starterDir . '/manifest.json';

		if (!file_exists($manifestPath)) {
			return null;
		}

		$json = file_get_contents($manifestPath);
		if ($json === false) {
			return null;
		}

		$data = json_decode($json, true);
		if (!is_array($data)) {
			return null;
		}

		return new StarterManifest($data, $starterDir);
	}

	/**
	 * Copy template files from a starter to the builder directory.
	 */
	private function copyTemplateFiles(string $starterDir, bool $force): int
	{
		$copied     = 0;
		$categories = ['layouts', 'pages', 'partials', 'macros'];

		foreach ($categories as $category) {
			$copied += $this->templateMigration->importDirectory(
				$starterDir . '/' . $category,
				$category,
				$force,
			);
		}

		return $copied;
	}

	/**
	 * Copy a starter's `assets/` directory (CSS, JS, images, fonts) into
	 * the docroot's assets directory. Mirrors the layout `cms.builder.css()`
	 * and friends use to reference these files at render time.
	 *
	 * Skips when the starter doesn't ship any assets, or when the docroot
	 * isn't configured. Existing files are skipped unless `$force` is set so
	 * a re-run doesn't clobber user customizations.
	 */
	private function copyAssetFiles(string $starterDir, bool $force): int
	{
		$source = $starterDir . '/assets';
		if (!is_dir($source)) {
			return 0;
		}

		$docroot = $this->builderConfig->getDocroot();
		if ($docroot === '') {
			$this->logger->warning('Skipping starter asset copy — docroot not configured');

			return 0;
		}

		$target = rtrim($docroot, '/') . '/' . trim($this->builderConfig->getAssetsPath(), '/');
		if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
			$this->logger->warning('Could not create assets directory', ['path' => $target]);

			return 0;
		}

		$copied   = 0;
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
					$this->logger->warning('Could not create asset subdirectory', ['path' => $dest]);
				}

				continue;
			}

			if (!$force && file_exists($dest)) {
				continue;
			}

			if (@copy($item->getPathname(), $dest)) {
				$copied++;
			} else {
				$this->logger->warning('Could not copy starter asset', [
					'source' => $item->getPathname(),
					'dest'   => $dest,
				]);
			}
		}

		return $copied;
	}

	/**
	 * Run the starter's `jumpstart.json` through the JumpStart importer.
	 * Returns null when the starter doesn't ship a jumpstart file; otherwise
	 * an `{ok: bool, error?: string}` tuple summarizing the import result.
	 *
	 * Failure to import does NOT fail the scaffold — templates have already
	 * been copied and the user can re-run jumpstart manually via
	 * `tcms jumpstart:import`.
	 *
	 * @return array{ok:bool,error?:string}|null
	 */
	private function importJumpStart(StarterManifest $manifest): ?array
	{
		$path = $manifest->directory . '/jumpstart.json';
		if (!file_exists($path)) {
			return null;
		}

		try {
			// Builder scaffolding runs from the CLI (`tcms builder:init`), which
			// has shell trust, so starters may seed system collections.
			$result = $this->jumpStartImporter->importFromFile($path, allowSystemCollections: true);
		} catch (\Throwable $e) {
			$this->logger->warning('Starter jumpstart import threw', [
				'starter' => $manifest->name,
				'error'   => $e->getMessage(),
			]);

			return ['ok' => false, 'error' => $e->getMessage()];
		}

		if (!$result->success) {
			$this->logger->warning('Starter jumpstart import reported errors', [
				'starter' => $manifest->name,
				'message' => $result->message,
			]);
		}

		return $result->success
			? ['ok' => true]
			: ['ok' => false, 'error' => $result->message];
	}

	private function startersDir(): string
	{
		return PathResolver::packageRoot() . '/resources/builder/starters';
	}
}
