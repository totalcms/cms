<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Storage;

use League\Flysystem\UnableToReadFile;

/**
 * A JSON file that holds a list of records under one root key, such as
 * `{"clients": [...]}`. Reads and writes go through {@see AtomicJsonStore},
 * so every such file gets the same treatment: an atomic temp-file-and-rename
 * write, a lock around each read-modify-write, a private (0600) file, and a
 * refusal to overwrite a file that no longer parses.
 *
 * Subclasses name the root key and add their own finders; the OAuth client
 * and grant repositories each used to hand-roll all of this.
 */
abstract class JsonListRepository
{
	public function __construct(
		private readonly AtomicJsonStore $store,
		private readonly string $path,
	) {
	}

	/**
	 * The key under which the list is stored.
	 */
	abstract protected function rootKey(): string;

	/**
	 * Every record, as stored.
	 *
	 * @return list<array<string,mixed>>
	 */
	protected function entries(): array
	{
		try {
			return $this->listOf($this->store->load($this->path, CorruptPolicy::Throw));
		} catch (UnableToReadFile) {
			// A file that exists but cannot be read (permissions, removed between
			// stat and read) is "no records yet", quietly — the contract the OAuth
			// stores have always had. A file that reads but does not parse is a
			// different matter and still throws.
			return [];
		}
	}

	/**
	 * The first record whose $field equals $value.
	 *
	 * @return array<string,mixed>|null
	 */
	protected function findEntry(string $field, mixed $value): ?array
	{
		foreach ($this->entries() as $entry) {
			if (($entry[$field] ?? null) === $value) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Replace the record with this `id`, or append it.
	 *
	 * @param array<string,mixed> $entry
	 */
	protected function upsert(array $entry): void
	{
		$this->write(static function (array $entries) use ($entry): array {
			foreach ($entries as $i => $existing) {
				if (($existing['id'] ?? null) === ($entry['id'] ?? null)) {
					$entries[$i] = $entry;

					return $entries;
				}
			}
			$entries[] = $entry;

			return $entries;
		});
	}

	/**
	 * Keep the records $keep accepts; drop the rest. The file is written only
	 * when something was dropped.
	 *
	 * @param callable(array<string,mixed>): bool $keep
	 *
	 * @return int records removed
	 */
	protected function removeWhere(callable $keep): int
	{
		$removed = 0;

		$this->write(function (array $entries) use ($keep, &$removed): ?array {
			$kept    = array_values(array_filter($entries, $keep));
			$removed = count($entries) - count($kept);

			return $removed > 0 ? $kept : null;
		});

		return $removed;
	}

	/**
	 * @param callable(list<array<string,mixed>>): (list<array<string,mixed>>|null) $fn returns the new list, or null to leave the file alone
	 */
	private function write(callable $fn): void
	{
		// Returning the loaded data unchanged tells the store there is nothing
		// to write.
		$this->store->mutate($this->path, function (array $data) use ($fn): array {
			$entries = $fn($this->listOf($data));
			if ($entries !== null) {
				$data[$this->rootKey()] = $entries;
			}

			return $data;
		}, CorruptPolicy::Throw, lock: true, secret: true);
	}

	/**
	 * @param array<string,mixed> $data
	 *
	 * @return list<array<string,mixed>>
	 */
	private function listOf(array $data): array
	{
		$list = $data[$this->rootKey()] ?? [];

		return is_array($list) ? array_values(array_filter($list, is_array(...))) : [];
	}
}
