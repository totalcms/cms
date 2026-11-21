<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\Field;

use TotalCMS\Bundled\S3Uploads\Storage\Pointer;
use TotalCMS\Bundled\S3Uploads\Storage\S3Fields;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

/**
 * The `s3depot` field: many files in the bucket on one property. The stored
 * value is a list of pointers, in the order the editor arranged them.
 */
class S3DepotField extends S3Field
{
	protected string $defaultFieldType = 's3depot';

	public function buildFormField(): string
	{
		$pointers = S3Fields::pointers($this->value, S3Fields::DEPOT);

		$cards = '';
		foreach ($pointers as $pointer) {
			$cards .= $this->item($pointer);
		}

		return $this->valueInput() . $this->wrap(
			$this->dropZone(true)
				. HTMLUtils::element('ul', $cards, ['class' => 's3-list'])
				// What the script copies for a file that is added in the browser.
				. HTMLUtils::element('template', $this->item(null), ['class' => 's3-item-template']),
			$pointers === [] ? 'empty' : 'filled',
			's3-field s3-depot',
		);
	}

	private function item(?Pointer $pointer): string
	{
		return $this->card($pointer, 'li', ['data-file-id' => $pointer->id ?? '', 'draggable' => 'true'], false);
	}
}
