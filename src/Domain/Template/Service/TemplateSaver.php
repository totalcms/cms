<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Template\Service;

use TotalCMS\Domain\Builder\Service\BuilderTemplatePaths;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\EventDispatcher;
use TotalCMS\Domain\Event\Payload\TemplateEventPayload;
use TotalCMS\Domain\Template\Data\DesignerMetadata;
use TotalCMS\Domain\Template\Data\TemplateData;
use TotalCMS\Domain\Template\Exception\TemplatesLockedException;
use TotalCMS\Domain\Template\Repository\TemplateRepository;

/**
 * Service.
 */
readonly class TemplateSaver
{
	public function __construct(
		private TemplateRepository $storage,
		private TemplateSnapshotService $snapshots,
		private EventDispatcher $eventDispatcher,
		private BuilderTemplatePaths $paths,
	) {
	}

	/**
	 * Save a Template. Captures a snapshot of the prior contents (if any)
	 * before overwriting, so users can restore from history.
	 *
	 * @throws \DomainException
	 * @throws TemplatesLockedException when templates are git-managed here
	 */
	public function saveTemplate(string $id, string $contents, ?string $folder = null): TemplateData
	{
		if ($this->paths->locked()) {
			throw new TemplatesLockedException();
		}

		$template = TemplateFactory::generateTemplate($id, $contents);

		if ($this->storage->reservedTemplateExists($id)) {
			throw new \DomainException("Cannot override a built-in template with the name $id.");
		}

		$existing = $this->storage->fetchBuilderTemplate($id, $folder);
		if ($existing instanceof TemplateData && $existing->contents !== $contents) {
			$this->snapshots->capture($id, $folder, $existing->contents);
		}

		$this->storage->saveTemplate($template, $folder);

		$this->eventDispatcher->dispatch(CoreEvent::TEMPLATE_SAVED, new TemplateEventPayload($id, $folder));

		return $template;
	}

	/**
	 * Save designer metadata for a template.
	 *
	 * @throws TemplatesLockedException when templates are git-managed here
	 */
	public function saveDesignerMeta(string $id, ?string $folder, DesignerMetadata $meta): void
	{
		if ($this->paths->locked()) {
			throw new TemplatesLockedException();
		}

		$this->storage->saveDesignerMeta($id, $folder, $meta);
	}
}
