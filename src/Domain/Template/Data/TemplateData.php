<?php

declare(strict_types=1);

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
	 * Which read layer this template was resolved from — one of
	 * BuilderTemplatePaths::LAYER_* ('project' | 'data' | 'built-in'), or '' for
	 * reserved/unknown. Set by the repository on fetch; lets the admin show a
	 * source badge and warn when a built-in default is being overridden.
	 */
	public string $source = '';

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
