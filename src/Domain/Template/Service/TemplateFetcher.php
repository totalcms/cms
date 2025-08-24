<?php

namespace TotalCMS\Domain\Template\Service;

use TotalCMS\Domain\Template\Data\TemplateData;
use TotalCMS\Domain\Template\Repository\TemplateRepository;

/**
 * Service.
 */
final readonly class TemplateFetcher
{
	private TemplateRepository $storage;

	public function __construct(TemplateRepository $storage)
	{
		$this->storage = $storage;
	}

	/**
	 * fetch a template.
	 *
	 * @param string $id
	 *
	 * @return TemplateData
	 */
	public function fetchTemplate(string $id): TemplateData
	{
		return $this->storage->fetchTemplate($id);
	}

	/**
	 * check if a template exists.
	 *
	 * @param string $id Template ID
	 *
	 * @return bool
	 */
	public function templateExists(string $id): bool
	{
		return $this->storage->templateExists($id);
	}
}
