<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Prompt\Data;

final readonly class PromptArgData
{
	public function __construct(
		public string $name,
		public string $description = '',
		public bool $required = false,
	) {
	}

	/**
	 * Accepts either an `id` key (deck-item shape — preferred) or a `name` key
	 * (legacy / programmatic construction).
	 *
	 * @param array<string,mixed> $data
	 */
	public static function fromArray(array $data): self
	{
		return new self(
			name: (string)($data['id'] ?? $data['name'] ?? ''),
			description: (string)($data['description'] ?? ''),
			required: (bool)($data['required'] ?? false),
		);
	}
}
