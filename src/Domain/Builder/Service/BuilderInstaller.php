<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Service;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Template\Service\TemplateFetcher;
use TotalCMS\Domain\Template\Service\TemplateMigrationService;
use TotalCMS\Domain\Template\Service\TemplateSaver;

/**
 * First-run setup and legacy migration for the Site Builder. Creates the
 * default pages collection and default layout when missing, and migrates
 * templates from the legacy `tcms-data/templates/` directory to the new
 * `tcms-data/builder/` structure.
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
		private TemplateFetcher $templateFetcher,
		private TemplateSaver $templateSaver,
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
	 * Ensure a default layout exists in the builder.
	 */
	public function ensureDefaultLayout(): void
	{
		if ($this->templateFetcher->templateExists('default', 'layouts')) {
			return;
		}

		$content = <<<'TWIG'
			<!DOCTYPE html>
			<html lang="en">
			<head>
				<meta charset="UTF-8">
				<meta name="viewport" content="width=device-width, initial-scale=1.0">
				<title>{% block title %}{{ cms.config('domain') }}{% endblock %}</title>
				<meta name="description" content="{% block description %}{% endblock %}">
				{% block head %}{% endblock %}
			</head>
			<body>
				{% block content %}{% endblock %}
			</body>
			</html>
			TWIG;

		$this->templateSaver->saveTemplate('default', $content, 'layouts');
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
