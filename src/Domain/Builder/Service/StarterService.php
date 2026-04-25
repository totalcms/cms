<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Builder\Data\StarterManifest;
use TotalCMS\Domain\Object\Service\ObjectSaver;
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
		private ObjectSaver $objectSaver,
		private TemplateLister $templateLister,
		private TemplateMigrationService $templateMigration,
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

		$starters = [];
		$entries   = scandir($startersDir);
		if ($entries === false) {
			return [];
		}

		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$manifestPath = $startersDir . '/' . $entry . '/manifest.json';
			if (!file_exists($manifestPath)) {
				continue;
			}

			$json = file_get_contents($manifestPath);
			if ($json === false) {
				continue;
			}

			$data = json_decode($json, true);
			if (!is_array($data)) {
				continue;
			}

			$starters[] = new StarterManifest($data, $startersDir . '/' . $entry);
		}

		return $starters;
	}

	/**
	 * Scaffold a new site from a starter template.
	 */
	public function scaffold(string $starterName, bool $force = false): OperationResult
	{
		$starterDir = $this->startersDir() . '/' . $starterName;
		$manifestPath = $starterDir . '/manifest.json';

		if (!file_exists($manifestPath)) {
			return OperationResult::failure("Starter '{$starterName}' not found");
		}

		$json = file_get_contents($manifestPath);
		if ($json === false) {
			return OperationResult::failure('Could not read manifest');
		}

		$data = json_decode($json, true);
		if (!is_array($data)) {
			return OperationResult::failure('Invalid manifest JSON');
		}

		$manifest = new StarterManifest($data, $starterDir);

		// Check if page templates already exist
		if (!$force && $this->templateLister->listBuilderTemplates('pages') !== []) {
			return OperationResult::failure('Templates already exist. Use --force to overwrite.');
		}

		// Copy template files
		$copied = $this->copyTemplateFiles($starterDir, $force);

		// Create builder-pages collection if it doesn't exist
		$this->builderConfig->ensurePagesCollection();

		// Create page objects from manifest
		$pagesCreated = $this->createPageObjects($manifest, $force);

		$this->logger->info('Starter scaffolded', [
			'starter' => $starterName,
			'files'   => $copied,
			'pages'   => $pagesCreated,
		]);

		return OperationResult::success(
			"Scaffolded '{$manifest->name}' starter: {$copied} files copied, {$pagesCreated} pages created",
			[
				'starter'      => $starterName,
				'filesCopied'  => $copied,
				'pagesCreated' => $pagesCreated,
			],
		);
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
	 * Create page objects from the starter manifest.
	 */
	private function createPageObjects(StarterManifest $manifest, bool $force): int
	{
		$created = 0;

		foreach ($manifest->pages as $page) {
			try {
				$this->objectSaver->saveObject($this->builderConfig->getPagesCollectionId(), [
					'id'     => $page['id'],
					'title'  => $page['title'],
					'path'   => $page['path'],
					'layout' => $page['layout'],
					'draft'  => false,
					'sort'   => $page['sort'],
				]);
				$created++;
			} catch (\DomainException) {
				// Object already exists — skip unless force
				if ($force) {
					// For force mode, we'd need ObjectUpdater — skip for now
					$this->logger->debug('Page object already exists, skipping', ['id' => $page['id']]);
				}
			}
		}

		return $created;
	}

	private function startersDir(): string
	{
		return PathResolver::packageRoot() . '/resources/builder/starters';
	}

}
