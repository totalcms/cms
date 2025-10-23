<?php

namespace TotalCMS\Transformer;

use League\Fractal;
use TotalCMS\Domain\Template\Data\TemplateData;

class TemplateMetaTransformer extends Fractal\TransformerAbstract
{
	/**
	 * Fractal transform for a template.
	 *
	 * @param TemplateData $template The template object
	 *
	 * @return array<string,string>
	 */
	public function transform(TemplateData $template): array
	{
		return $template->toArray();
	}
}
