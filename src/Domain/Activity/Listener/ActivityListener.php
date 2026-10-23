<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Activity\Listener;

use TotalCMS\Domain\Activity\Data\ActivityEntry;
use TotalCMS\Domain\Activity\Service\ActivityRecorder;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Object\Data\ObjectData;

/**
 * Turns core events into activity rows. Registered for every event in
 * {@see self::EVENTS} at priority 100, after the core listeners.
 *
 * Left out on purpose:
 *  - `import.created` / `import.updated` — one row per imported object would
 *    bury everything else; `import.completed` records the import once.
 *  - `collection.updated` when only the index or metadata moved
 *    (`configChanged: false`) — that fires on every object save.
 *  - downloads by visitors who are not signed in.
 */
final readonly class ActivityListener
{
	/** @var list<string> */
	public const EVENTS = [
		CoreEvent::OBJECT_CREATED,
		CoreEvent::OBJECT_UPDATED,
		CoreEvent::OBJECT_DELETED,
		CoreEvent::COLLECTION_CREATED,
		CoreEvent::COLLECTION_UPDATED,
		CoreEvent::COLLECTION_DELETED,
		CoreEvent::SCHEMA_SAVED,
		CoreEvent::SCHEMA_DELETED,
		CoreEvent::TEMPLATE_SAVED,
		CoreEvent::USER_LOGIN,
		CoreEvent::USER_LOGOUT,
		CoreEvent::IMPORT_COMPLETED,
		CoreEvent::BULK_DELETED,
		CoreEvent::EXTENSION_ENABLED,
		CoreEvent::EXTENSION_DISABLED,
		CoreEvent::DEVMODE_ENABLED,
		CoreEvent::DEVMODE_DISABLED,
		CoreEvent::CACHE_CLEARED,
		CoreEvent::ACCESS_DENIED,
		CoreEvent::FILE_DOWNLOADED,
	];

	/** Most changed field names listed in an update's summary. */
	private const MAX_CHANGED_FIELDS = 20;

	public function __construct(
		private ActivityRecorder $recorder,
	) {
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	public function handle(string $event, array $payload): void
	{
		$actor  = is_array($payload['actor'] ?? null) ? $payload['actor'] : [];
		$fields = $this->fieldsFor($event, $payload, $actor);

		if ($fields !== null) {
			$this->recorder->record($event, $actor, $fields);
		}
	}

	/**
	 * The row's fields for $event, or null when it is not recorded.
	 *
	 * @param array<string,mixed> $payload
	 * @param array<string,mixed> $actor
	 *
	 * @return array{level?: string, userId?: string, collection?: string, objectId?: string, summary?: string, detail?: array<string,mixed>}|null
	 */
	private function fieldsFor(string $event, array $payload, array $actor): ?array
	{
		$collection = $this->str($payload, 'collection');

		return match ($event) {
			CoreEvent::OBJECT_UPDATED => [
				'collection' => $collection,
				'objectId'   => $this->str($payload, 'id'),
				'summary'    => $this->changedFields($payload),
			],
			CoreEvent::OBJECT_CREATED, CoreEvent::OBJECT_DELETED => [
				'collection' => $collection,
				'objectId'   => $this->str($payload, 'id'),
			],
			CoreEvent::COLLECTION_UPDATED => ($payload['configChanged'] ?? true) === false ? null : ['collection' => $collection],
			CoreEvent::COLLECTION_CREATED, CoreEvent::COLLECTION_DELETED => [
				'collection' => $collection,
				'level'      => $event === CoreEvent::COLLECTION_DELETED ? ActivityEntry::LEVEL_WARNING : ActivityEntry::LEVEL_INFO,
			],
			CoreEvent::SCHEMA_SAVED, CoreEvent::SCHEMA_DELETED => ['objectId' => $this->str($payload, 'schema')],
			CoreEvent::TEMPLATE_SAVED                          => ['objectId' => $this->str($payload, 'path')],
			CoreEvent::EXTENSION_ENABLED, CoreEvent::EXTENSION_DISABLED => ['objectId' => $this->str($payload, 'id')],
			// The actor is still anonymous while the login request runs; the
			// payload names the user who signed in.
			CoreEvent::USER_LOGIN, CoreEvent::USER_LOGOUT => $this->str($actor, 'userId') === ''
				? ['userId' => $this->str($payload, 'user')]
				: [],
			CoreEvent::IMPORT_COMPLETED => [
				'collection' => $collection,
				'summary'    => sprintf('%d created, %d updated', (int)($payload['created'] ?? 0), (int)($payload['updated'] ?? 0)),
			],
			CoreEvent::BULK_DELETED => [
				'collection' => $collection,
				'level'      => ActivityEntry::LEVEL_WARNING,
				'summary'    => sprintf('%d objects', (int)($payload['count'] ?? 0)),
				'detail'     => ['ids' => is_array($payload['ids'] ?? null) ? array_values($payload['ids']) : []],
			],
			CoreEvent::ACCESS_DENIED => [
				'collection' => $collection,
				'objectId'   => $this->str($payload, 'id'),
				'level'      => ActivityEntry::LEVEL_WARNING,
				'summary'    => trim(sprintf('%s %s: %s', $this->str($payload, 'operation'), $this->str($payload, 'resource'), $this->str($payload, 'reason')), ' :'),
				'detail'     => array_filter([
					'method' => $this->str($payload, 'method'),
					'path'   => $this->str($payload, 'path'),
					'route'  => $this->str($payload, 'route'),
				]),
			],
			CoreEvent::FILE_DOWNLOADED => $this->str($actor, 'kind') === 'anonymous' ? null : [
				'collection' => $collection,
				'objectId'   => $this->str($payload, 'id'),
				'summary'    => ltrim($this->str($payload, 'subpath') . '/' . $this->str($payload, 'filename'), '/'),
				'detail'     => [
					'property'  => $this->str($payload, 'property'),
					'protected' => ($payload['protected'] ?? false) === true,
				],
			],
			default => [],
		};
	}

	/**
	 * Names of the top-level fields an update changed, never their values.
	 *
	 * @param array<string,mixed> $payload
	 */
	private function changedFields(array $payload): string
	{
		$new = $payload['object'] ?? null;
		$old = $payload['previous'] ?? null;
		if (!$new instanceof ObjectData || !$old instanceof ObjectData) {
			return '';
		}

		$after   = $new->toArray();
		$before  = $old->toArray();
		$changed = [];
		foreach (array_unique([...array_keys($after), ...array_keys($before)]) as $key) {
			if (($after[$key] ?? null) != ($before[$key] ?? null)) {
				$changed[] = (string)$key;
			}
		}

		$shown = array_slice($changed, 0, self::MAX_CHANGED_FIELDS);
		$more  = count($changed) - count($shown);

		return implode(', ', $shown) . ($more > 0 ? " +{$more}" : '');
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function str(array $data, string $key): string
	{
		$value = $data[$key] ?? '';

		return is_scalar($value) ? (string)$value : '';
	}
}
