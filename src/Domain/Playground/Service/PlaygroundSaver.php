<?php

namespace TotalCMS\Domain\Playground\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Playground\Data\PlaygroundData;

final class PlaygroundSaver
{
	public function __construct(
		private PlaygroundLister $playgroundLister,
		private ObjectSaver $objectSaver,
	) {
	}

	/** @param array<string,mixed> $data */
	public function saveSnippet(array $data): ObjectData
	{
		$this->playgroundLister->ensureCollection();

		return $this->objectSaver->saveObject(PlaygroundData::COLLECTION_ID, $data);
	}
}
