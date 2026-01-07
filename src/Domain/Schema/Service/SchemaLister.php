<?php

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
	 * List custom Schemas.
	 *
	 * @return array<SchemaData>
	 */
	public function listCustomSchemas(): array
	{
		return $this->storage->listCustomSchemas();
	}

	/**
	 * Get a sorted list of unique category values from all schemas.
	 *
	 * @return array<string>
	 */
	public function listCategories(): array
	{
		$schemas    = $this->listAllSchemas();
		$categories = array_map(fn (SchemaData $s): string => $s->category ?? '', $schemas);

		// Filter out empty values and get unique sorted list
		$categories = array_filter($categories, fn (string $c): bool => $c !== '');
		$categories = array_unique($categories);
		sort($categories);

		return $categories;
	}
}
