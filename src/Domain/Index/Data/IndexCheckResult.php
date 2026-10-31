<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Index\Data;

/**
 * How a collection's index, its object files and its object count disagree.
 * Produced by IndexChecker; nothing here is ever repaired.
 */
final class IndexCheckResult
{
	/**
	 * @param list<string> $unreadable   object files that do not parse
	 * @param list<string> $missingFiles ids in the index with no object file
	 * @param ?int         $recordedCount the collection's totalObjects; null when not calculated yet
	 * @param list<string> $notIndexed   object files the index does not list
	 */
	public function __construct(
		public readonly string $collection,
		public readonly int $objects,
		public readonly ?int $indexed,
		public readonly bool $indexReadable,
		public readonly ?int $recordedCount,
		public readonly array $unreadable = [],
		public readonly array $missingFiles = [],
		public readonly array $notIndexed = [],
	) {
	}

	public function isConsistent(): bool
	{
		return $this->problems() === [];
	}

	/** @return list<string> one line per problem, in a fixed order */
	public function problems(): array
	{
		$problems = [];

		if ($this->indexed === null) {
			$problems[] = $this->indexReadable ? 'There is no index file' : 'The index file is unreadable';
		}
		foreach ($this->unreadable as $id) {
			$problems[] = "Unreadable object: {$id}";
		}
		foreach ($this->missingFiles as $id) {
			$problems[] = "In the index but no file: {$id}";
		}
		foreach ($this->notIndexed as $id) {
			$problems[] = "File not in the index: {$id}";
		}
		if ($this->recordedCount !== null && $this->recordedCount !== $this->objects) {
			$problems[] = "The collection's object count says {$this->recordedCount}; there are {$this->objects} on disk";
		}

		return $problems;
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'objects'       => $this->objects,
			'indexed'       => $this->indexed,
			'indexReadable' => $this->indexReadable,
			'recordedCount' => $this->recordedCount,
			'unreadable'    => $this->unreadable,
			'missingFiles'  => $this->missingFiles,
			'notIndexed'    => $this->notIndexed,
			'problems'      => $this->problems(),
		];
	}
}
