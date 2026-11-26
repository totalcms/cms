<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Data;

/**
 * What one run of the commerce installer did.
 *
 * Collections are named by id, emails as `mailer/{id}`.
 */
final readonly class InstallReport
{
	/**
	 * @param list<string> $created  made by this run
	 * @param list<string> $existing already there, and left as they were
	 * @param list<string> $errors   what could not be installed, and why
	 */
	public function __construct(
		public array $created = [],
		public array $existing = [],
		public array $errors = [],
	) {
	}

	public function ok(): bool
	{
		return $this->errors === [];
	}

	/** @return array{created: list<string>, existing: list<string>, errors: list<string>} */
	public function toArray(): array
	{
		return ['created' => $this->created, 'existing' => $this->existing, 'errors' => $this->errors];
	}
}
