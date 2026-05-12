<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Orphan\Data;

class OrphanReport
{
	/** @var array<OrphanEntry> */
	private array $entries = [];

	public int $collectionsScanned        = 0;
	public int $objectsScanned            = 0;
	public int $relationalPropertiesFound = 0;
	public int $orphanedReferencesFound   = 0;
	public string $scannedAt;

	public function __construct()
	{
		$this->scannedAt = date('c');
	}

	public function addEntry(OrphanEntry $entry): void
	{
		$this->entries[] = $entry;
		$this->orphanedReferencesFound += count($entry->orphanedIds);
	}

	/** @return array<OrphanEntry> */
	public function getEntries(): array
	{
		return $this->entries;
	}

	public function isEmpty(): bool
	{
		return $this->entries === [];
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'summary' => [
				'collectionsScanned'        => $this->collectionsScanned,
				'objectsScanned'            => $this->objectsScanned,
				'relationalPropertiesFound' => $this->relationalPropertiesFound,
				'orphanedReferencesFound'   => $this->orphanedReferencesFound,
				'scannedAt'                 => $this->scannedAt,
				'isEmpty'                   => $this->isEmpty(),
			],
			'entries' => array_map(
				fn (OrphanEntry $entry): array => $entry->toArray(),
				$this->entries
			),
		];
	}
}
