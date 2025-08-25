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
}
