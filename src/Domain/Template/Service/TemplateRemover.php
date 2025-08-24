<?php

namespace TotalCMS\Domain\Template\Service;

use TotalCMS\Domain\Template\Repository\TemplateRepository;

/**
 * Service.
 */
final readonly class TemplateRemover
{
	private TemplateRepository $storage;

	public function __construct(TemplateRepository $storage)
	{
		$this->storage = $storage;
	}

	/**
	 * Delete a Template.
	 *
	 * @param string $id
	 *
	 * @throws \DomainException
	 *
	 * @return bool
	 */
	public function deleteTemplate(string $id): bool
	{
		if ($this->storage->reservedTemplateExists($id)) {
			throw new \DomainException('Cannot delete a built-in template.');
		}

		return $this->storage->deleteTemplate($id);
	}
}
