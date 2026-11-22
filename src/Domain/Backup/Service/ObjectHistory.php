<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Backup\Service;

use TotalCMS\Domain\Activity\Data\ActivityEntry;
use TotalCMS\Domain\Activity\Repository\ActivityRepository;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\PropertyDefinition;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * An object's backup history, read for a person: when it changed, who changed
 * it, which fields, and what a restore would do. It reads; it restores
 * nothing ({@see ObjectRestorer} does that).
 *
 * A snapshot holds the record as it was BEFORE a change. So the entry for a
 * snapshot describes the change that followed it: the snapshot compared with
 * the next newer one, or with the live record for the newest. Restoring an
 * entry puts the record back to how it was before that change.
 *
 * Snapshots do not say who made the change. The activity log does, so "who"
 * comes from the log entry made at the same moment, and is empty when the log
 * is off or the entry has been pruned.
 *
 * Nothing here returns the value of a password or a server-only field:
 * {@see SnapshotComparison} only says that one changed.
 */
final readonly class ObjectHistory
{
	/** How far apart, in seconds, a snapshot and the log entry of the same save may be. */
	private const SAME_MOMENT = 2;

	public function __construct(
		private BackupStore $store,
		private ObjectRestorer $restorer,
		private ObjectFetcher $objects,
		private SchemaFetcher $schemas,
		private FileBackupStore $files,
		private ActivityRepository $activity,
	) {
	}

	public function isLive(string $collection, string $id): bool
	{
		return $this->objects->existsObject($collection, $id);
	}

	/**
	 * One entry per snapshot, newest first.
	 *
	 * `event` is `deleted` for the final state of a deleted object and
	 * `changed` otherwise. `changed` lists the top-level fields that differ,
	 * by label; it is empty when nothing a person would see differs.
	 *
	 * @return list<array{file:string, at:string, format:string, bytes:int, event:'changed'|'deleted', who:string, changed:list<string>}>
	 */
	public function entries(string $collection, string $id): array
	{
		$snapshots = $this->store->listObjectSnapshots($collection, $id);
		if ($snapshots === []) {
			return [];
		}

		$properties = $this->properties($collection);
		$comparison = $this->comparison();
		$recorded   = $this->activity->forObject($collection, $id);
		$after      = $this->current($collection, $id);
		$entries    = [];

		foreach ($snapshots as $snapshot) {
			$before  = $this->decoded($collection, $id, $snapshot['file']);
			$deleted = $after === null;
			$changed = [];

			if (!$deleted && $before !== null) {
				foreach ($comparison->compare($properties, $before, $after) as $change) {
					$changed[$change['property']] = $this->label($properties, $change['property']);
				}
			}

			$entries[] = [
				'file'    => $snapshot['file'],
				'at'      => $snapshot['at'],
				'format'  => $snapshot['format'],
				'bytes'   => $snapshot['bytes'],
				'event'   => $deleted ? 'deleted' : 'changed',
				'who'     => $this->who($recorded, $snapshot['at'], $deleted),
				'changed' => array_values($changed),
			];

			// The state before this change is the state after the one before it.
			$after = $before ?? $after ?? [];
		}

		return $entries;
	}

	/**
	 * What changed at one snapshot, field by field: the snapshot's value and
	 * the value that followed. For a file the snapshot named, `beforeState`
	 * says whether it is `live`, `kept` or `gone`; so does `state` on each
	 * file a gallery or depot lost. Empty for the final state of a deleted
	 * object, where nothing followed.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function changes(string $collection, string $id, string $file): array
	{
		$snapshots = array_column($this->store->listObjectSnapshots($collection, $id), 'file');
		$index     = array_search($file, $snapshots, true);
		$before    = $index === false ? null : $this->decoded($collection, $id, $file);
		if ($index === false || $before === null) {
			return [];
		}

		$after = $index === 0 ? $this->current($collection, $id) : $this->decoded($collection, $id, $snapshots[$index - 1]);
		if ($after === null) {
			return [];
		}

		$changes = $this->comparison()->compare($this->properties($collection), $before, $after);

		foreach ($changes as &$change) {
			if ($change['kind'] === 'file') {
				$change['beforeState'] = $this->files->state($collection, $id, $change['property'], $change['path'], $change['before'], $change['beforeSize']);
			} elseif ($change['kind'] === 'files') {
				foreach ($change['removed'] as &$removed) {
					$removed['state'] = $this->files->state($collection, $id, $change['property'], $removed['path'], $removed['name'], $removed['size']);
				}
				unset($removed);
			}
		}
		unset($change);

		return $changes;
	}

	/**
	 * What restoring a snapshot would do now, without doing it.
	 *
	 * - `changes`: the fields that would change, from their value now to the
	 *   snapshot's. Empty for a deleted object, which comes back whole.
	 * - `back`: files the snapshot names that are kept and would come back.
	 * - `lost`: files it names that are neither on disk nor kept.
	 * - `passwordKept`: the snapshot holds another password, which a restore
	 *   from the admin leaves as it is now.
	 *
	 * @param string|null $property only this top-level property
	 *
	 * @return array{live:bool, changes:list<array<string,mixed>>, back:list<array{property:string,path:string,name:string}>, lost:list<array{property:string,path:string,name:string}>, passwordKept:bool}|null null when the snapshot cannot be read
	 */
	public function preview(string $collection, string $id, string $file, ?string $property = null): ?array
	{
		$snapshot = $this->decoded($collection, $id, $file);
		if ($snapshot === null) {
			return null;
		}

		$current = $this->current($collection, $id);
		$changes = $this->comparison()->compare($this->properties($collection), $current ?? [], $snapshot);
		$preview = ['live' => $current !== null, 'changes' => [], 'back' => [], 'lost' => [], 'passwordKept' => false];

		foreach ($changes as $change) {
			if ($property !== null && $change['property'] !== $property) {
				continue;
			}
			if ($change['kind'] === 'secret' && ($change['password'] ?? false) && $current !== null && $property === null) {
				$preview['passwordKept'] = true;

				continue;
			}

			$wanted = match ($change['kind']) {
				'file'  => [['path' => $change['path'], 'name' => $change['after'], 'size' => $change['afterSize']]],
				'files' => $change['added'],
				default => [],
			};
			foreach ($wanted as $named) {
				$state = $this->files->state($collection, $id, $change['property'], $named['path'], $named['name'], $named['size']);
				if ($state === 'kept' || $state === 'gone') {
					$preview[$state === 'kept' ? 'back' : 'lost'][] = ['property' => $change['property'], 'path' => $named['path'], 'name' => $named['name']];
				}
			}

			if ($current !== null) {
				$preview['changes'][] = $change;
			}
		}

		return $preview;
	}

	/**
	 * The objects of a collection that were deleted and still have snapshots,
	 * most recently deleted first.
	 *
	 * @return list<array{id:string, at:string, file:string, snapshots:int}>
	 */
	public function deleted(string $collection): array
	{
		$deleted = [];
		foreach ($this->store->listObjectIds($collection) as $id) {
			if ($this->objects->existsObject($collection, $id)) {
				continue;
			}
			$snapshots = $this->store->listObjectSnapshots($collection, $id);
			if ($snapshots === []) {
				continue;
			}
			$deleted[] = ['id' => $id, 'at' => $snapshots[0]['at'], 'file' => $snapshots[0]['file'], 'snapshots' => count($snapshots)];
		}

		usort($deleted, fn (array $a, array $b): int => $b['at'] <=> $a['at']);

		return $deleted;
	}

	/**
	 * A name for a deleted object and who deleted it, from its final state.
	 *
	 * @return array{title:string, who:string}
	 */
	public function describe(string $collection, string $id, string $file, string $at): array
	{
		$record = $this->decoded($collection, $id, $file) ?? [];
		$title  = '';
		foreach (['title', 'name', 'label'] as $key) {
			if (is_string($record[$key] ?? null) && $record[$key] !== '') {
				$title = $record[$key];

				break;
			}
		}

		return ['title' => $title, 'who' => $this->who($this->activity->forObject($collection, $id, 20), $at, true)];
	}

	/** @return array<string,mixed>|null the live record, or null when the object does not exist */
	private function current(string $collection, string $id): ?array
	{
		try {
			return $this->objects->existsObject($collection, $id) ? $this->objects->fetchObject($collection, $id)->toArray() : null;
		} catch (\Throwable) {
			return null;
		}
	}

	/** @return array<string,mixed>|null a snapshot's record, or null when it cannot be read */
	private function decoded(string $collection, string $id, string $file): ?array
	{
		try {
			return $this->restorer->read($collection, $id, $file);
		} catch (\Throwable) {
			return null;
		}
	}

	/** @return array<string,mixed> */
	private function properties(string $collection): array
	{
		try {
			return $this->schemas->fetchSchemaForCollection($collection)->properties;
		} catch (\Throwable) {
			return [];
		}
	}

	/** @param array<string,mixed> $properties */
	private function label(array $properties, string $property): string
	{
		$label = is_array($properties[$property] ?? null) ? ($properties[$property]['label'] ?? '') : '';

		return is_string($label) && $label !== '' ? $label : $property;
	}

	private function comparison(): SnapshotComparison
	{
		return new SnapshotComparison(function (array $definition): array {
			try {
				$reference = PropertyDefinition::extractSchemaRef($definition);

				return $reference === null ? [] : $this->schemas->fetchSchema(basename($reference, '.json'))->properties;
			} catch (\Throwable) {
				return [];
			}
		});
	}

	/**
	 * Who made the save a snapshot was taken at, from the activity log.
	 *
	 * @param list<ActivityEntry> $recorded
	 */
	private function who(array $recorded, string $at, bool $deleted): string
	{
		$time  = (int)strtotime($at);
		$event = $deleted ? CoreEvent::OBJECT_DELETED : CoreEvent::OBJECT_UPDATED;

		foreach ($recorded as $entry) {
			if ($entry->event === $event && abs($entry->createdAt - $time) <= self::SAME_MOMENT) {
				return $entry->actorLabel();
			}
		}

		return '';
	}
}
