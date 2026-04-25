<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Data;

readonly class GenerateResult
{
	/**
	 * @param list<string> $generated  Stub files written
	 * @param list<string> $skipped    Draft pages skipped
	 * @param list<string> $cleaned    Orphan stubs removed
	 * @param list<string> $errors     Error messages
	 */
	public function __construct(
		public array $generated = [],
		public array $skipped = [],
		public array $cleaned = [],
		public array $errors = [],
	) {
	}

	public function isSuccess(): bool
	{
		return $this->errors === [];
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'success'   => $this->isSuccess(),
			'generated' => count($this->generated),
			'skipped'   => count($this->skipped),
			'cleaned'   => count($this->cleaned),
			'errors'    => $this->errors,
			'files'     => $this->generated,
		];
	}
}
