<?php

namespace TotalCMS\Domain\Template\Service;

use TotalCMS\Domain\Template\Data\TemplateData;
use TotalCMS\Domain\Template\Repository\TemplateRepository;

/**
 * Service.
 */
final readonly class TemplateSaver
{
	public function __construct(private TemplateRepository $storage)
    {
    }

	/**
     * Save a Template.
     *
     *
     * @throws \DomainException
     *
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
