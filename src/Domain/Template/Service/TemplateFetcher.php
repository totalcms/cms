<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Template\Service;

use TotalCMS\Domain\Template\Data\TemplateData;
use TotalCMS\Domain\Template\Repository\TemplateRepository;

/**
 * Service.
 */
readonly class TemplateFetcher
{
	public function __construct(private TemplateRepository $storage)
	{
	}

	/**
	 * fetch a template.
	 */
	public function fetchTemplate(string $id, ?string $folder = null): TemplateData
	{
		return $this->storage->fetchTemplate($id, $folder);
	}

	/**
	 * check if a template exists.
	 *
	 * @param string $id Template ID
	 */
	public function templateExists(string $id, ?string $folder = null): bool
	{
		return $this->storage->builderTemplateExists($id, $folder) || $this->storage->reservedTemplateExists($id);
	}
}
