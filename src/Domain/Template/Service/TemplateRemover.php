<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Template\Service;

use TotalCMS\Domain\Builder\Service\BuilderTemplatePaths;
use TotalCMS\Domain\Template\Exception\TemplatesLockedException;
use TotalCMS\Domain\Template\Repository\TemplateRepository;

/**
 * Service.
 */
readonly class TemplateRemover
{
	public function __construct(
		private TemplateRepository $storage,
		private BuilderTemplatePaths $paths,
	) {
	}

	/**
	 * Delete a Template.
	 *
	 * @throws \DomainException
	 * @throws TemplatesLockedException when templates are git-managed here
	 */
	public function deleteTemplate(string $id, ?string $folder = null): bool
	{
		if ($this->paths->locked()) {
			throw new TemplatesLockedException();
		}

		if ($this->storage->reservedTemplateExists($id)) {
			throw new \DomainException('Cannot delete a built-in template.');
		}

		return $this->storage->deleteTemplate($id, $folder);
	}
}
