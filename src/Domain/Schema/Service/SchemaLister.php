<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Schema\Service;

use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;

/**
 * Service.
 */
readonly class SchemaLister
{
	public function __construct(private SchemaRepository $storage)
	{
	}

	/**
	 * List all Schemas.
	 *
	 * @return array<SchemaData>
	 */
	public function listAllSchemas(): array
	{
		return array_merge(
			$this->listReservedSchemas(),
			$this->listExtensionSchemas(),
			$this->listCustomSchemas()
		);
	}

	/**
	 * List reserved Schemas.
	 *
	 * @return array<SchemaData>
	 */
	public function listReservedSchemas(): array
	{
		return $this->storage->listReservedSchemas();
	}

	/**
	 * List extension-provided Schemas.
	 *
	 * @return array<SchemaData>
	 */
	public function listExtensionSchemas(): array
	{
		return $this->storage->listExtensionSchemas();
	}

	/**
	 * List custom Schemas.
	 *
	 * @return array<SchemaData>
	 */
	public function listCustomSchemas(): array
	{
		return $this->storage->listCustomSchemas();
	}

	/**
	 * Check if a schema ID is reserved (built-in or extension-provided).
	 */
	public function isReservedSchema(string $id): bool
	{
		return in_array($id, $this->storage->reservedSchemasIds(), true);
	}

	/**
	 * Get a sorted list of unique category values from all schemas.
	 *
	 * @return array<string>
	 */
	public function listCategories(): array
	{
		$schemas    = $this->listAllSchemas();
		$categories = array_map(fn (SchemaData $s): string => $s->category, $schemas);

		// Filter out empty values and get unique sorted list
		$categories = array_filter($categories, fn (string $c): bool => $c !== '');
		$categories = array_unique($categories);
		sort($categories);

		return $categories;
	}
}
