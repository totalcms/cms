<?php

namespace TotalCMS\Domain\Template\Service;

use TotalCMS\Domain\Template\Data\TemplateData;
use TotalCMS\Domain\Template\Repository\TemplateRepository;

/**
 * Service.
 */
final readonly class TemplateFetcher
{
	public function __construct(private TemplateRepository $storage)
	{
	}

	/**
	 * fetch a template.
	 */
	public function fetchTemplate(string $id): TemplateData
	{
		return $this->storage->fetchTemplate($id);
	}

	/**
	 * check if a template exists.
	 *
	 * @param string $id Template ID
	 */
	public function templateExists(string $id): bool
	{
		return $this->storage->templateExists($id);
	}
}
