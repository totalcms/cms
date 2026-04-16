<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Template\Service;

use TotalCMS\Domain\Template\Data\DesignerMetadata;
use TotalCMS\Domain\Template\Data\TemplateData;
use TotalCMS\Domain\Template\Repository\TemplateRepository;

/**
 * Service.
 */
readonly class TemplateSaver
{
	public function __construct(private TemplateRepository $storage)
	{
	}

	/**
	 * Save a Template.
	 *
	 * @throws \DomainException
	 */
	public function saveTemplate(string $id, string $contents, ?string $folder = null): TemplateData
	{
		$template = TemplateFactory::generateTemplate($id, $contents);

		if ($this->storage->reservedTemplateExists($id)) {
			throw new \DomainException("Cannot override a built-in template with the name $id.");
		}

		$this->storage->saveTemplate($template, $folder);

		return $template;
	}

	/**
	 * Save designer metadata for a template.
	 */
	public function saveDesignerMeta(string $id, ?string $folder, DesignerMetadata $meta): void
	{
		$this->storage->saveDesignerMeta($id, $folder, $meta);
	}
}
