<?php

namespace TotalCMS\Domain\Template\Service;

use TotalCMS\Domain\Template\Data\TemplateData;
use TotalCMS\Domain\Template\Repository\TemplateRepository;

/**
 * Service.
 */
final class TemplateSaver
{
	private TemplateRepository $storage;

	public function __construct(TemplateRepository $storage)
	{
		$this->storage = $storage;
	}

	/**
	 * Save a Template.
	 *
	 * @param string $id
	 * @param string $contents
	 *
	 * @throws \DomainException
	 *
	 * @return TemplateData
	 */
	public function saveTemplate(string $id, string $contents): TemplateData
	{
		$template = TemplateFactory::generateTemplate($id, $contents);

		if ($this->storage->reservedTemplateExists($id)) {
			throw new \DomainException("Cannot override a built-in template with the name $id.");
		}

		$this->storage->saveTemplate($template);

		return $template;
	}
}
