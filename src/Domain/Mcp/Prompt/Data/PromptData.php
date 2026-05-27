<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Prompt\Data;

final readonly class PromptData
{
	/**
	 * @param list<PromptArgData> $args
	 */
	public function __construct(
		public string $name,
		public string $description,
		public string $body,
		public array $args = [],
		public string $targetCollection = '',
		public string $access = '',
	) {
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function fromArray(array $data): self
	{
		// Args are stored as a deck (dictionary keyed by arg name). The deck key
		// IS the argument name; the inner `id` field, if present, is a duplicate
		// kept in sync by the form builder. Tolerate a list-of-objects shape too
		// in case anything seeds args via JumpStart / CLI before the form normalises.
		$args = [];
		if (isset($data['args']) && is_array($data['args'])) {
			foreach ($data['args'] as $key => $rawArg) {
				if (!is_array($rawArg)) {
					continue;
				}
				$name = is_string($key)
					? $key
					: (string)($rawArg['id'] ?? $rawArg['name'] ?? '');
				if ($name === '') {
					continue;
				}
				$args[] = new PromptArgData(
					name:        $name,
					description: (string)($rawArg['description'] ?? ''),
					required:    (bool)($rawArg['required'] ?? false),
				);
			}
		}

		return new self(
			name:             (string)($data['name'] ?? ''),
			description:      (string)($data['description'] ?? ''),
			body:             (string)($data['body'] ?? ''),
			args:             $args,
			targetCollection: (string)($data['targetCollection'] ?? ''),
			access:           (string)($data['access'] ?? ''),
		);
	}
}
