<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Data;

/**
 * Represents a dashboard widget added by an extension.
 */
final readonly class DashboardWidget
{
	/**
	 * $extensionId is stamped by ExtensionManager when widgets are collected —
	 * extensions don't (and shouldn't) set it themselves. Templates use it
	 * to apply the access-group extension grant.
	 */
	public function __construct(
		public string $id,
		public string $label,
		public string $template,
		public string $position = 'main',
		public int $priority = 50,
		public string $extensionId = '',
	) {
	}

	/**
	 * Copy of this widget attributed to the extension that registered it.
	 */
	public function withExtensionId(string $extensionId): self
	{
		return new self($this->id, $this->label, $this->template, $this->position, $this->priority, $extensionId);
	}
}
