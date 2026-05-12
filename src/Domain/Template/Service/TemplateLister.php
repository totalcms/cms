<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Template\Service;

use TotalCMS\Domain\Template\Repository\TemplateRepository;

/**
 * Service.
 */
readonly class TemplateLister
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
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 *
	 * @return array<string>
	 */
	public function listBuilderTemplates(?string $folder = null, bool $recursive = false): array
	{
		return $this->storage->listBuilderTemplates($folder, $recursive);
	}

	/**
	 * List all templates.
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 *
	 * @return array<string>
	 */
	public function listAllTemplates(?string $folder = null, bool $recursive = false): array
	{
		return array_merge($this->listReservedTemplates(), $this->listBuilderTemplates($folder, $recursive));
	}
}
