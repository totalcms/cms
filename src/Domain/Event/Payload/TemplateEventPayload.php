<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Payload;

/**
 * Payload for `template.saved` events.
 *
 * `id` is the template path without folder prefix (e.g. `about`, `partials/header`).
 * `folder` is the optional sub-folder (e.g. `pages`, `layouts`, null for root).
 */
readonly class TemplateEventPayload extends EventPayload
{
	public function __construct(
		public string $id,
		public ?string $folder = null,
	) {
	}

	/**
	 * Full path including folder, used by listeners that want a single
	 * identifier (e.g. `pages/about`).
	 */
	public function path(): string
	{
		if ($this->folder === null || $this->folder === '') {
			return $this->id;
		}

		return $this->folder . '/' . $this->id;
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'id'     => $this->id,
			'folder' => $this->folder,
			'path'   => $this->path(),
		];
	}
}
