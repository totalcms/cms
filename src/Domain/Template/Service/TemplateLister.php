<?php

namespace TotalCMS\Domain\Template\Service;

use TotalCMS\Domain\Template\Repository\TemplateRepository;

/**
 * Service.
 */
final readonly class TemplateLister
{
	public function __construct(private TemplateRepository $storage)
    {
    }

	/**
	 * List reserved templates.
	 *
	 * @return array<string>
	 */
	public function listReservedTemplates(): array
	{
		return $this->storage->listReservedTemplates();
	}

	/**
	 * List custom templates.
	 *
	 * @return array<string>
	 */
	public function listCustomTemplates(): array
	{
		return $this->storage->listCustomTemplates();
	}

	/**
	 * List all templates.
	 *
	 * @return array<string>
	 */
	public function listAllTemplates(): array
	{
		return array_merge($this->listReservedTemplates(), $this->listCustomTemplates());
	}
}
