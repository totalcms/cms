<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\Field;

use TotalCMS\Bundled\S3Uploads\Storage\Pointer;

/** The `s3file` field: one file in the bucket. The stored value is one pointer. */
class S3FileField extends S3Field
{
	protected string $defaultFieldType = 's3file';

	public function buildFormField(): string
	{
		$pointer = Pointer::fromArray($this->value);

		return $this->valueInput() . $this->wrap(
			$this->dropZone() . $this->card($pointer, 'div', ['hidden' => !$pointer instanceof Pointer]),
			$pointer instanceof Pointer ? 'filled' : 'empty',
		);
	}
}
