<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Service;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Support\Config;

/**
 * Source of truth for Site Builder configuration lookups: which collection
 * holds the pages, whether it exists, and where the docroot is. First-run
 * setup and legacy migration live in {@see BuilderInstaller}.
 */
readonly class BuilderConfigService
{
	public const DEFAULT_COLLECTION_ID = 'builder-pages';
	public const DEFAULT_SCHEMA_ID     = 'builder-page';

	public function __construct(
		private Config $config,
		private CollectionFetcher $collectionFetcher,
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
