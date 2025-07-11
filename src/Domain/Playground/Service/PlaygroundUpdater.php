<?php

namespace TotalCMS\Domain\Playground\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Playground\Data\PlaygroundData;

final class PlaygroundUpdater
{

	public function __construct(
		private ObjectUpdater $objectUpdater,
	) {
	}

	/** @param array<string,mixed> $data */
	public function updateSnippet(string $id, array $data): ObjectData
	{
		return $this->objectUpdater->updateObject(PlaygroundData::COLLECTION_ID, $id, $data);
	}
}