<?php

declare(strict_types=1);

namespace Tests\Unit\XmlRpc\Stubs;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;

/**
 * Readonly (parent is a readonly class), named + namespaced (anonymous
 * readonly classes are PHP 8.3+; CI runs the 8.2 floor). See
 * XmlRpcStubObjectUrlBuilder for the full rationale.
 */
readonly class BlogRegistryStubCollectionLister extends CollectionLister
{
	/** @param array<CollectionData> $collections */
	public function __construct(private array $collections)
	{
	}

	public function listCollectionsWithSchema(string $schemaId): array
	{
		return array_values(array_filter(
			$this->collections,
			fn (CollectionData $collection): bool => $collection->schema === $schemaId
		));
	}
}
