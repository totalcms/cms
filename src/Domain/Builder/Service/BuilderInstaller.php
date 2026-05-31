<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Service;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Template\Service\TemplateMigrationService;

/**
 * First-run setup and legacy migration for the Site Builder. Creates the
 * default pages collection when missing, and migrates templates from the
 * legacy `tcms-data/templates/` directory to the new `tcms-data/builder/`
 * structure. The default `layouts/default.twig` ships as a built-in floor
 * (`resources/builder/defaults/`), so no layout materialization is needed.
 *
 * BuilderConfigService is the source of truth for the configured pages
 * collection ID; this service consumes it to decide what to install.
 */
readonly class BuilderInstaller
{
	public function __construct(
		private BuilderConfigService $builderConfig,
		private CollectionFetcher $collectionFetcher,
		private CollectionSaver $collectionSaver,
		private TemplateMigrationService $templateMigration,
	) {
	}

	/**
	 * Ensure the pages collection exists, creating it if needed. Only
	 * auto-creates the default `builder-pages` collection — sites that
	 * configured a custom collection name must create it themselves so we
	 * don't accidentally clobber an existing schema.
	 */
	public function ensurePagesCollection(): void
	{
		$collectionId = $this->builderConfig->getPagesCollectionId();

		if ($this->collectionFetcher->collectionExists($collectionId)) {
			return;
		}

		// Only auto-create the default collection
		if ($collectionId !== BuilderConfigService::DEFAULT_COLLECTION_ID) {
			return;
		}

		$this->collectionSaver->saveCollection([
			'id'     => BuilderConfigService::DEFAULT_COLLECTION_ID,
			'schema' => BuilderConfigService::DEFAULT_SCHEMA_ID,
			'name'   => 'Pages',
			'sortBy' => 'sort',
		]);
	}

	/**
	 * Migrate templates from the legacy tcms-data/templates/ directory
	 * to the new tcms-data/builder/ directory structure.
	 */
	public function migrateFromTemplatesDir(): void
	{
		$this->templateMigration->migrateFromLegacyTemplates();
	}
}
