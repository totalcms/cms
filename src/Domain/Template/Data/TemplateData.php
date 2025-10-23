<?php

namespace TotalCMS\Domain\Template\Data;

/**
 * Template Data object.
 */
class TemplateData
{
	public string $contents;
	public string $id;

	/**
	 * Convert to array.
	 *
	 * @return array<string,string>
	 */
	public function toArray(): array
	{
		return [
			'id'       => $this->id,
			'template' => $this->contents,
		];
	}
}
