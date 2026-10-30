<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Media\Data;

use TotalCMS\Domain\Object\Data\ObjectData;

/**
 * A file PropertyFileStore put on a field: the object as saved, the field
 * type that took it, the stored name, and a preview URL ('' when the type has
 * none or one could not be built).
 */
final readonly class StoredFile
{
	public function __construct(
		public ObjectData $object,
		public string $type,
		public string $name,
		public string $preview,
	) {
	}
}
