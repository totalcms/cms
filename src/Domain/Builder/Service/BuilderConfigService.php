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
	public const DEFAULT_ASSETS_PATH   = 'assets';

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

	/**
	 * Get the configured assets path (relative to docroot). Defaults to
	 * `assets` when unset or blank — same fallback the asset scanner and
	 * `cms.builder.css()` helper apply, kept centralized here.
	 */
	public function getAssetsPath(): string
	{
		$path = $this->config->builder['assetsPath'] ?? '';

		return is_string($path) && $path !== '' ? $path : self::DEFAULT_ASSETS_PATH;
	}

	/**
	 * Whether the live-reload preview feature is enabled. Drives whether the
	 * SSE script is injected into HTML responses for admin sessions and
	 * whether the SSE endpoint accepts connections at all.
	 *
	 * Defaults to `true` — admins editing in the Builder almost always want
	 * the live preview, and the script is fully gated by admin session so
	 * non-admins never see it.
	 */
	public function isLiveReloadEnabled(): bool
	{
		$value = $this->config->builder['liveReload'] ?? true;

		return (bool)$value;
	}
}
