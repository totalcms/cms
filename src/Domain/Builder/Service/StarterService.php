<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Builder\Data\StarterManifest;
use TotalCMS\Domain\JumpStart\Service\JumpStartImporter;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Domain\Template\Service\TemplateMigrationService;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\OperationResult;
use TotalCMS\Support\PathResolver;

readonly class StarterService
{
	private LoggerInterface $logger;

	public function __construct(
		private BuilderConfigService $builderConfig,
		private BuilderInstaller $builderInstaller,
		private BuilderOrderService $orderService,
		private ObjectSaver $objectSaver,
		private ObjectUpdater $objectUpdater,
		private TemplateLister $templateLister,
		private TemplateMigrationService $templateMigration,
		private JumpStartImporter $jumpStartImporter,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->createLogger('builder');
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
			$manifest = $this->loadManifest($entry);
			if ($manifest !== null) {
				$starters[] = $manifest;
			}
		}

		return $starters;
	}

	/**
	 * Scaffold a new site from a starter template.
	 *
	 * @param bool $importDemoData When true, also imports the starter's
	 *                             `jumpstart.json` (if present) — schemas,
	 *                             collections, sample objects. Opt-in so a
	 *                             clean slate is the default.
	 */
	public function scaffold(string $starterName, bool $force = false, bool $importDemoData = false): OperationResult
	{
		$manifest = $this->loadManifest($starterName);
		if ($manifest === null) {
			return OperationResult::failure("Starter '{$starterName}' not found");
		}

		// Check if page templates already exist
		if (!$force && $this->templateLister->listBuilderTemplates('pages') !== []) {
			return OperationResult::failure('Templates already exist. Use --force to overwrite.');
		}

		// Copy template files
		$copied = $this->copyTemplateFiles($manifest->directory, $force);

		// Copy starter assets (CSS, JS, images) into the docroot's assets dir.
		// Done separately from template copy because the destination is the
		// public docroot, not tcms-data/builder/.
		$assetsCopied = $this->copyAssetFiles($manifest->directory, $force);

		// Create builder-pages collection if it doesn't exist
		$this->builderInstaller->ensurePagesCollection();

		// Create / update page objects from manifest
		$pagesCreated = $this->createPageObjects($manifest, $force);

		// Always seed the order file from the manifest so the sidebar reflects
		// the manifest's intended order — including on a forced re-init where
		// existing pages got updated rather than created. Hierarchy is flat
		// today; users can drag to nest.
		$this->seedOrderFile($manifest);

		// Optional demo data import. Runs after pages are in place so the
		// imported objects can reference page templates if needed.
		$demo = $importDemoData ? $this->importDemoData($manifest) : null;

		$this->logger->info('Starter scaffolded', [
			'starter'      => $starterName,
			'files'        => $copied,
			'assetFiles'   => $assetsCopied,
			'pages'        => $pagesCreated,
			'demoData'     => $demo !== null,
		]);

		$assetsNote = $assetsCopied > 0 ? ", {$assetsCopied} asset(s) copied" : '';
		$message = "Scaffolded '{$manifest->name}' starter: {$copied} files copied{$assetsNote}, {$pagesCreated} pages created";
		if ($demo !== null) {
			$message .= $demo['ok']
				? ', demo data imported'
				: ', demo data import failed (' . ($demo['error'] ?? 'unknown') . ')';
		}

		return OperationResult::success($message, [
			'starter'       => $starterName,
			'filesCopied'   => $copied,
			'assetsCopied'  => $assetsCopied,
			'pagesCreated'  => $pagesCreated,
			'demoData'      => $demo,
		]);
	}

	/**
	 * Read and parse a starter's manifest.json. Returns null if the starter
	 * directory or manifest is missing/invalid.
	 */
	private function loadManifest(string $starterName): ?StarterManifest
	{
		$starterDir   = $this->startersDir() . '/' . $starterName;
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
	 * Create page objects from the starter manifest. With $force=true, an
	 * existing page with the same id is updated instead of skipped, so the
	 * starter's manifest stays the source of truth on a forced re-init.
	 *
	 * Returns the count of pages successfully created OR updated.
	 */
	private function createPageObjects(StarterManifest $manifest, bool $force): int
	{
		$collectionId = $this->builderConfig->getPagesCollectionId();
		$processed    = 0;

		foreach ($manifest->pages as $page) {
			if ($page['id'] === '') {
				$this->logger->warning('Skipping starter page with empty id', ['title' => $page['title']]);

				continue;
			}

			$record = [
				'id'       => $page['id'],
				'title'    => $page['title'],
				'route'    => $page['route'],
				'template' => $page['template'],
				'draft'    => false,
				'nav'      => $page['nav'],
			];

			try {
				$this->objectSaver->saveObject($collectionId, $record);
				$processed++;
			} catch (\DomainException) {
				if (!$force) {
					continue;
				}
				try {
					$this->objectUpdater->updateObject($collectionId, $page['id'], $record);
					$processed++;
				} catch (\Throwable $e) {
					$this->logger->warning('Failed to update existing starter page', [
						'id'    => $page['id'],
						'error' => $e->getMessage(),
					]);
				}
			}
		}

		return $processed;
	}

	/**
	 * Write the order file from the manifest's page order so the sidebar
	 * shows pages in the intended sequence on first visit. Pages that
	 * weren't successfully saved are filtered out by the order service's
	 * own reconciliation against the page index.
	 */
	private function seedOrderFile(StarterManifest $manifest): void
	{
		$tree = [];
		foreach ($manifest->pages as $page) {
			if ($page['id'] === '') {
				continue;
			}
			$tree[] = ['id' => $page['id'], 'children' => []];
		}

		if ($tree === []) {
			return;
		}

		$this->orderService->write($this->builderConfig->getPagesCollectionId(), $tree);
	}

	/**
	 * Run the starter's `jumpstart.json` through the JumpStart importer.
	 * Returns null when the starter doesn't ship demo data; otherwise an
	 * `{ok: bool, error?: string}` tuple summarizing the import result.
	 *
	 * Failure to import demo data does NOT fail the scaffold — the templates
	 * + page records have already been created and the user can re-import
	 * the demo data manually via `tcms jumpstart:import`.
	 *
	 * @return array{ok:bool,error?:string}|null
	 */
	private function importDemoData(StarterManifest $manifest): ?array
	{
		$path = $manifest->directory . '/jumpstart.json';
		if (!file_exists($path)) {
			return null;
		}

		try {
			$result = $this->jumpStartImporter->importFromFile($path);
		} catch (\Throwable $e) {
			$this->logger->warning('Starter demo data import threw', [
				'starter' => $manifest->name,
				'error'   => $e->getMessage(),
			]);

			return ['ok' => false, 'error' => $e->getMessage()];
		}

		if (!$result->success) {
			$this->logger->warning('Starter demo data import reported errors', [
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
