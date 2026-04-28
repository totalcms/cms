<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Service;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Template\Service\TemplateFetcher;
use TotalCMS\Domain\Template\Service\TemplateMigrationService;
use TotalCMS\Domain\Template\Service\TemplateSaver;
use TotalCMS\Support\Config;

readonly class BuilderConfigService
{
	public const DEFAULT_COLLECTION_ID = 'builder-pages';
	public const DEFAULT_SCHEMA_ID     = 'builder-page';

	public function __construct(
		private Config $config,
		private CollectionFetcher $collectionFetcher,
		private CollectionSaver $collectionSaver,
		private TemplateMigrationService $templateMigration,
		private TemplateFetcher $templateFetcher,
		private TemplateSaver $templateSaver,
	) {
	}

	/**
	 * Get the configured pages collection ID.
	 */
	public function getPagesCollectionId(): string
	{
		$id = $this->config->builder['pagesCollection'] ?? '';

		return is_string($id) && $id !== '' ? $id : self::DEFAULT_COLLECTION_ID;
	}

	/**
	 * Ensure the pages collection exists, creating it if needed.
	 * Only auto-creates the default builder-pages collection.
	 * Custom collections must be created manually.
	 */
	public function ensurePagesCollection(): void
	{
		$collectionId = $this->getPagesCollectionId();

		if ($this->collectionFetcher->collectionExists($collectionId)) {
			return;
		}

		// Only auto-create the default collection
		if ($collectionId !== self::DEFAULT_COLLECTION_ID) {
			return;
		}

		$this->collectionSaver->saveCollection([
			'id'     => self::DEFAULT_COLLECTION_ID,
			'schema' => self::DEFAULT_SCHEMA_ID,
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
	 * Delegates to TemplateRepository which handles the move via Flysystem.
	 */
	public function migrateFromTemplatesDir(): void
	{
		$this->templateMigration->migrateFromLegacyTemplates();
	}

	/**
	 * Check if the configured pages collection exists.
	 */
	public function pagesCollectionExists(): bool
	{
		return $this->collectionFetcher->collectionExists($this->getPagesCollectionId());
	}

	public function getDocroot(): string
	{
		return $this->config->docroot;
	}


}
