<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Download;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Service\FileFetcher;

/**
 * The file a digital product is: what its buyers paid for.
 *
 * It is one file field on the product, `download`. This is the only way the
 * store reads it, and {@see guards()} is how every other route that serves
 * files learns to keep away from it.
 */
final readonly class ProductDownload
{
	public const PROPERTY = 'download';

	private const SCHEMA = 'commerce-product';

	public function __construct(
		private CommerceSettings $settings,
		private CollectionFetcher $collections,
		private FileFetcher $files,
	) {
	}

	/**
	 * Whether this is a product's download: a file no ordinary route may
	 * serve. Decided by the collection's schema, not its name, so a store
	 * that keeps its products under another id is covered, and a site's own
	 * collection with a `download` field is left alone.
	 */
	public function guards(string $collection, string $property): bool
	{
		if ($property !== self::PROPERTY || $collection === '') {
			return false;
		}

		try {
			$found = $this->collections->fetchCollection($collection);
		} catch (\Throwable) {
			return false;
		}

		return $found instanceof CollectionData && $found->schema === self::SCHEMA;
	}

	/** The product's file, or null when it has none. */
	public function file(string $productId): ?FileData
	{
		if ($productId === '') {
			return null;
		}

		try {
			$products = $this->settings->collection('products');
			if (!$this->files->fileExists($products, $productId, self::PROPERTY)) {
				return null;
			}

			$file = $this->files->fetchFile($products, $productId, self::PROPERTY);
		} catch (\Throwable) {
			// A product that was deleted, or a record with no file behind it.
			return null;
		}

		return $file->name !== '' ? $file : null;
	}

	/**
	 * The file's bytes.
	 *
	 * @return resource
	 */
	public function open(string $productId): mixed
	{
		return $this->files->streamFile($this->settings->collection('products'), $productId, self::PROPERTY);
	}
}
