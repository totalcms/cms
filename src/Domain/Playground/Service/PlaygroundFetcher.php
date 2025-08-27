<?php

namespace TotalCMS\Domain\Playground\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Playground\Data\PlaygroundData;

readonly class PlaygroundFetcher
{
	public function __construct(
		private ObjectFetcher $objectFetcher,
	) {
	}

	public function getSnippet(string $id): ObjectData
	{
		return $this->objectFetcher->fetchObject(PlaygroundData::COLLECTION_ID, $id);
	}

	public function snippetExists(string $id): bool
	{
		return $this->objectFetcher->existsObject(PlaygroundData::COLLECTION_ID, $id);
	}
}
