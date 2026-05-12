<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Playground\Service;

use TotalCMS\Domain\Object\Service\ObjectRemover;
use TotalCMS\Domain\Playground\Data\PlaygroundData;

readonly class PlaygroundRemover
{
	public function __construct(
		private ObjectRemover $objectRemover,
	) {
	}

	public function deleteSnippet(string $id): bool
	{
		return $this->objectRemover->deleteObject(PlaygroundData::COLLECTION_ID, $id);
	}
}
