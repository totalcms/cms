<?php

namespace TotalCMS\Domain\Template\Service;

use TotalCMS\Domain\Template\Data\TemplateData;

/**
 * Service.
 */
final class TemplateFactory
{
	/**
	 * create a template object.
	 *
	 * @param string $id
	 * @param string $template
	 *
	 * @return TemplateData
	 */
	public static function generateTemplate(string $id, string $template): TemplateData
	{
		$templateData           = new TemplateData();
		$templateData->id       = $id;
		$templateData->contents = $template;

		return $templateData;
	}
}
