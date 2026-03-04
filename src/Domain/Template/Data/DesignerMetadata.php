<?php

namespace TotalCMS\Domain\Template\Data;

/**
 * Designer metadata for templates.
 * Stored as companion .designer.json files alongside .twig files.
 */
class DesignerMetadata
{
	public bool $designerEnabled = false;
	public string $designerToken = '';

	/**
	 * Convert to array.
	 *
	 * @return array<string,bool|string>
	 */
	public function toArray(): array
	{
		return [
			'designerEnabled' => $this->designerEnabled,
			'designerToken'   => $this->designerToken,
		];
	}

	/**
	 * Create from array.
	 *
	 * @param array<string,mixed> $data
	 */
	public static function fromArray(array $data): self
	{
		$meta = new self();
		$meta->designerEnabled = (bool)($data['designerEnabled'] ?? false);
		$meta->designerToken   = (string)($data['designerToken'] ?? '');

		return $meta;
	}
}
