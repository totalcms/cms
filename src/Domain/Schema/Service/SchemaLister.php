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
		return $this->sortById(array_merge(
			$this->listReservedSchemas(),
			$this->listExtensionSchemas(),
			$this->listCustomSchemas()
		));
	}

	/**
	 * Directory order is the filesystem's — alphabetical on APFS, hash order
	 * on ext4 — so anything that lists schemas (the schema page, the
	 * Inherit From picker, every select built from them) read differently on
	 * a Mac and on Linux. One order, by id, everywhere.
	 *
	 * @param array<SchemaData> $schemas
	 *
	 * @return list<SchemaData>
	 */
	private function sortById(array $schemas): array
	{
		usort($schemas, static fn (SchemaData $a, SchemaData $b): int => strnatcmp(mb_strtolower($a->id), mb_strtolower($b->id)));

		return $schemas;
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
