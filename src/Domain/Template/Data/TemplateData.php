<?php

namespace TotalCMS\Domain\Template\Data;

/**
 * Template Data object.
 */
class TemplateData
{
	public string $contents;
	public string $id;
	public ?DesignerMetadata $designer = null;

	/**
	 * Convert to array.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array
	{
		$data = [
			'id'       => $this->id,
			'template' => $this->contents,
		];

		if ($this->designer instanceof DesignerMetadata) {
			$data = array_merge($data, $this->designer->toArray());
		}

		return $data;
	}
}
