<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Collection\Service;

final readonly class ConversionReport
{
	/**
	 * @param array<string,string> $failed id → reason ('read' or 'write'),
	 *                                     for objects left untouched
	 */
	public function __construct(
		public string $collection,
		public string $from,
		public string $to,
		public int $converted,
		public int $skipped,
		public array $failed,
		public bool $dryRun,
	) {
	}
}
