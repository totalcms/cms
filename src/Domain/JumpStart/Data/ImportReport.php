<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JumpStart\Data;

use Psr\Log\LoggerInterface;

/**
 * What a JumpStart import did: the human-readable result and error lines
 * the CLI and admin show, and the per-section tallies the summary reports.
 * Sections count as they record, so the summary never has to parse its
 * own messages.
 */
final class ImportReport
{
	public const SCHEMAS     = 'schemas_created';
	public const COLLECTIONS = 'collections_created';
	public const TEMPLATES   = 'templates_created';
	public const OBJECTS     = 'objects_created';
	public const FACTORY     = 'factory_items_created';

	/** @var list<string> */
	private array $results = [];

	/** @var list<string> */
	private array $errors = [];

	/** @var array<string,int> */
	private array $counts = [
		self::SCHEMAS     => 0,
		self::COLLECTIONS => 0,
		self::TEMPLATES   => 0,
		self::OBJECTS     => 0,
		self::FACTORY     => 0,
	];

	public function __construct(private readonly LoggerInterface $logger)
	{
	}

	/**
	 * Record a result line. `$section` is one of the tally constants (null
	 * for lines that count toward none, like an applied page order), and
	 * `$amount` how many items the line stands for — a bulk factory run
	 * reports its whole batch in one line.
	 */
	public function result(?string $section, string $message, int $amount = 1): void
	{
		$this->results[] = $message;
		$this->logger->info($message);

		if ($section !== null) {
			$this->counts[$section] += $amount;
		}
	}

	public function error(string $message): void
	{
		$this->errors[] = $message;
		$this->logger->error($message);
	}

	public function hasErrors(): bool
	{
		return $this->errors !== [];
	}

	/** @return list<string> */
	public function errors(): array
	{
		return $this->errors;
	}

	/** @return array<string,int> */
	public function summary(): array
	{
		return $this->counts + ['total_errors' => count($this->errors)];
	}

	/** @return array{results: list<string>, errors: list<string>, summary: array<string,int>} */
	public function toData(): array
	{
		return ['results' => $this->results, 'errors' => $this->errors, 'summary' => $this->summary()];
	}
}
