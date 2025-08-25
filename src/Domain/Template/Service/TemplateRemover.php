<?php

namespace TotalCMS\Domain\Template\Service;

use TotalCMS\Domain\Template\Repository\TemplateRepository;

/**
 * Service.
 */
final readonly class TemplateRemover
{
	public function __construct(private TemplateRepository $storage)
	{
	}

	/**
	 * Delete a Template.
	 *
	 * @throws \DomainException
	 */
	public function deleteTemplate(string $id): bool
	{
		if ($this->storage->reservedTemplateExists($id)) {
			throw new \DomainException('Cannot delete a built-in template.');
		}

		return $this->storage->deleteTemplate($id);
	}
}
