<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Media\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Data\DepotData;

/**
 * Keeps a whole-object save from dropping files from a depot that takes public
 * uploads. A visitor can send a file at any moment, so an admin page loaded
 * before that holds a file list without it, and its Save would write that
 * list back.
 *
 * On such a depot the stored list decides which files and folders exist:
 * every structural change (upload, delete, move, rename, new folder) has its
 * own route that writes the stored list itself. The save only brings what the
 * form edits on a file it lists, plus the depot's own password and protected
 * flag. A file the save leaves out stays; one it lists that is no longer
 * stored is not brought back. Depots with no grant are left as they are.
 */
readonly class GrantedDepotMerger
{
	public function __construct(
		private PublicUploadPolicy $policy,
	) {
	}

	/**
	 * $data with the stored file list kept in every granted depot.
	 *
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	public function keepStoredFiles(string $collection, ObjectData $stored, array $data): array
	{
		foreach ($stored->properties as $name => $property) {
			if (!$property instanceof DepotData || $this->policy->grantFor($collection, (string)$name) === null) {
				continue;
			}

			$depot    = $property->transform();
			$incoming = $data[$name] ?? null;
			if (!is_array($incoming)) {
				// Leaving the depot out of a PUT would empty it.
				$data[$name] = $depot;
				continue;
			}

			$incoming['files'] = $this->mergeFolder($depot['files'], is_array($incoming['files'] ?? null) ? $incoming['files'] : []);
			$data[$name]       = $incoming;
		}

		return $data;
	}

	/**
	 * The stored entries, in stored order, each with the form's edits on top.
	 *
	 * @param array<array<string,mixed>> $stored
	 * @param array<mixed>               $incoming
	 *
	 * @return array<array<string,mixed>>
	 */
	private function mergeFolder(array $stored, array $incoming): array
	{
		$sent = [];
		foreach ($incoming as $entry) {
			if (is_array($entry) && is_string($entry['name'] ?? null)) {
				$sent[$this->key($entry)] = $entry;
			}
		}

		$merged = [];
		foreach ($stored as $entry) {
			$match = $sent[$this->key($entry)] ?? null;

			if (($entry['mime'] ?? '') === 'folder') {
				$files          = is_array($match['files'] ?? null) ? $match['files'] : [];
				$entry['files'] = $this->mergeFolder(is_array($entry['files'] ?? null) ? $entry['files'] : [], $files);
				$merged[]       = $entry;
				continue;
			}

			// The download count is the server's: the page holds the count it loaded with.
			$merged[] = $match === null ? $entry : array_merge($entry, $match, ['count' => $entry['count'] ?? 0]);
		}

		return $merged;
	}

	/** @param array<mixed> $entry */
	private function key(array $entry): string
	{
		$kind = ($entry['mime'] ?? '') === 'folder' ? 'folder' : 'file';
		$name = $entry['name'] ?? '';

		return $kind . ':' . (is_string($name) ? $name : '');
	}
}
