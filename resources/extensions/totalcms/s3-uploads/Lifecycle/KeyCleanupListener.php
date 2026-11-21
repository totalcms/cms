<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\Lifecycle;

use Psr\Log\LoggerInterface;
use TotalCMS\Bundled\S3Uploads\S3\S3Client;
use TotalCMS\Bundled\S3Uploads\S3\S3Exception;
use TotalCMS\Bundled\S3Uploads\S3\S3Settings;
use TotalCMS\Bundled\S3Uploads\Serving\DownloadCounter;
use TotalCMS\Bundled\S3Uploads\Storage\KeyBuilder;
use TotalCMS\Bundled\S3Uploads\Storage\Pointer;
use TotalCMS\Bundled\S3Uploads\Storage\S3Fields;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectPatcher;

/**
 * Keeps the bucket in step with the objects:
 *
 * - object.updated: a file that was replaced or removed is deleted.
 * - object.deleted: every file of the object is deleted, and its counts.
 * - object.created: a clone gets its own copy of each file, so deleting the
 *   original cannot take the clone's files with it.
 *
 * Only keys under the object's own path are ever deleted. A pointer that
 * names another object's key, or a key outside the prefix, is left alone:
 * stored values can be written by REST, MCP or an import.
 *
 * Nothing here throws. A bucket that cannot be reached must not fail a save.
 *
 * The listener is registered whether or not a bucket is configured: a
 * capability an enabled extension gains later starts switched off, and sites
 * enable the extension before they configure it.
 */
final class KeyCleanupListener
{
	/** @var array<string,true> objects being patched by onCreated, as "collection/id" */
	private array $adopting = [];

	public function __construct(
		private readonly S3Settings $settings,
		private readonly S3Fields $fields,
		private readonly KeyBuilder $keys,
		private readonly S3Client $client,
		private readonly DownloadCounter $counter,
		private readonly ObjectPatcher $patcher,
		private readonly LoggerInterface $logger,
	) {
	}

	/** @param array<string,mixed> $payload */
	public function onUpdated(array $payload): void
	{
		[$collection, $id, $object, $previous] = $this->read($payload);
		if (!$object instanceof ObjectData || !$previous instanceof ObjectData || isset($this->adopting[$collection . '/' . $id])) {
			return;
		}

		$this->guard("cleaning up after an update to {$collection}/{$id}", function () use ($collection, $id, $object, $previous): void {
			$kept     = $this->ownedKeys($collection, $id, $object, ownedOnly: false);
			$reverted = $this->revertedKeys($collection, $object, $previous);
			foreach ($this->ownedKeys($collection, $id, $previous) as $key) {
				if (in_array($key, $kept, true)) {
					continue;
				}
				if (in_array($key, $reverted, true)) {
					$this->logger->warning("S3 Uploads: {$collection}/{$id} was saved with an older file than the one it had, most likely from a form that was open before the file was replaced. The newer file was left in the bucket.");

					continue;
				}
				$this->delete($key);
			}

			$this->counter->drop($collection, $id, $this->goneCounts($collection, $object, $previous));
		});
	}

	/** @param array<string,mixed> $payload */
	public function onDeleted(array $payload): void
	{
		[$collection, $id, , $previous] = $this->read($payload);
		if (!$previous instanceof ObjectData) {
			return;
		}

		$this->guard("cleaning up after deleting {$collection}/{$id}", function () use ($collection, $id, $previous): void {
			foreach ($this->ownedKeys($collection, $id, $previous) as $key) {
				$this->delete($key);
			}
			$this->counter->forget($collection, $id);
		});
	}

	/** @param array<string,mixed> $payload */
	public function onCreated(array $payload): void
	{
		[$collection, $id, $object] = $this->read($payload);
		if (!$object instanceof ObjectData) {
			return;
		}

		$this->guard("copying the files of {$collection}/{$id}", function () use ($collection, $id, $object): void {
			$stored  = $object->toArray();
			$changes = [];

			foreach ($this->fields->forCollection($collection) as $property => $field) {
				$folder = $this->folder($field['settings']);
				if ($this->keys->ownedPrefix($collection, $id, $property, $folder) === null) {
					continue; // a folder property owns nothing, so there is nothing to adopt
				}

				$pointers = S3Fields::pointers($stored[$property] ?? null, $field['field']);
				$adopted  = array_map(fn (Pointer $p): Pointer => $this->adopt($p, $collection, $id, $property), $pointers);
				if ($adopted == $pointers) {
					continue;
				}

				$values             = array_map(fn (Pointer $p): array => $p->toArray(), $adopted);
				$changes[$property] = $field['field'] === S3Fields::DEPOT ? $values : ($values[0] ?? null);
			}

			if ($changes === []) {
				return;
			}

			// The patch fires object.updated for this object; its old pointers
			// belong to the original, so that event has nothing to clean up.
			$this->adopting[$collection . '/' . $id] = true;

			try {
				$this->patcher->patchObject($collection, $id, $changes);
			} finally {
				unset($this->adopting[$collection . '/' . $id]);
			}
		});
	}

	/**
	 * A pointer that sits under another object's path, copied to this object's
	 * own path. Returned unchanged when it is already owned, is outside the
	 * prefix, or the copy fails.
	 */
	private function adopt(Pointer $pointer, string $collection, string $id, string $property): Pointer
	{
		if (!$this->keys->isInsidePrefix($pointer->key) || $this->keys->isOwnedBy($pointer->key, $collection, $id, $property, null)) {
			return $pointer;
		}

		try {
			$key = $this->keys->build($collection, $id, $property, $pointer->name, null);
			$this->client->copyObject($pointer->key, $key);
		} catch (\Throwable $e) {
			$this->logger->error("S3 Uploads: could not copy a file for {$collection}/{$id} ({$this->describe($e)}). It still shares the original's file, so deleting the original will break it.");

			return $pointer;
		}

		return new Pointer(
			(string)KeyBuilder::idFromKey($key),
			$key,
			$pointer->name,
			$pointer->size,
			$pointer->mime,
			$pointer->etag,
			$pointer->duration,
			$pointer->uploadedAt,
			$pointer->uploadedBy,
		);
	}

	/**
	 * The keys of an object's s3 properties.
	 *
	 * @param bool $ownedOnly only keys under the object's own path (the ones safe to delete)
	 *
	 * @return list<string>
	 */
	private function ownedKeys(string $collection, string $id, ObjectData $object, bool $ownedOnly = true): array
	{
		$stored = $object->toArray();
		$keys   = [];

		foreach ($this->fields->forCollection($collection) as $property => $field) {
			$folder = $this->folder($field['settings']);
			foreach (S3Fields::pointers($stored[$property] ?? null, $field['field']) as $pointer) {
				if (!$ownedOnly || $this->keys->isOwnedBy($pointer->key, $collection, $id, $property, $folder)) {
					$keys[] = $pointer->key;
				}
			}
		}

		return $keys;
	}

	/**
	 * Keys of files that a save swapped for an OLDER upload.
	 *
	 * Replacing a file always stores a newer pointer. A save that puts an
	 * older one back comes from a stale form (a second tab, another editor),
	 * and the file it carries was already deleted when it was replaced.
	 * Deleting the newer file as well would leave the object with nothing.
	 *
	 * @return list<string>
	 */
	private function revertedKeys(string $collection, ObjectData $object, ObjectData $previous): array
	{
		$now    = $object->toArray();
		$before = $previous->toArray();
		$keys   = [];

		foreach ($this->fields->forCollection($collection) as $property => $field) {
			if ($field['field'] !== S3Fields::FILE) {
				continue;
			}
			$incoming = S3Fields::pointers($now[$property] ?? null, S3Fields::FILE)[0] ?? null;
			$dropped  = S3Fields::pointers($before[$property] ?? null, S3Fields::FILE)[0] ?? null;
			if ($incoming === null || $dropped === null || $incoming->key === $dropped->key) {
				continue;
			}

			$incomingAt = strtotime($incoming->uploadedAt);
			$droppedAt  = strtotime($dropped->uploadedAt);
			if ($incomingAt !== false && $droppedAt !== false && $incomingAt < $droppedAt) {
				$keys[] = $dropped->key;
			}
		}

		return $keys;
	}

	/**
	 * The counter keys of files this save took out of the object. A download
	 * count belongs to the stored file, as it does for a core file: a
	 * replaced or cleared `s3file` starts again, and a file removed from an
	 * `s3depot` takes its count with it.
	 *
	 * @return list<string>
	 */
	private function goneCounts(string $collection, ObjectData $object, ObjectData $previous): array
	{
		$now    = $object->toArray();
		$before = $previous->toArray();
		$gone   = [];

		foreach ($this->fields->forCollection($collection) as $property => $field) {
			$was = S3Fields::pointers($before[$property] ?? null, $field['field']);
			$is  = S3Fields::pointers($now[$property] ?? null, $field['field']);

			if ($field['field'] === S3Fields::FILE) {
				if (($was[0] ?? null)?->key !== ($is[0] ?? null)?->key) {
					$gone[] = $property;
				}

				continue;
			}

			$kept = array_map(fn (Pointer $pointer): string => $pointer->id, $is);
			foreach ($was as $pointer) {
				if (!in_array($pointer->id, $kept, true)) {
					$gone[] = $property . '/' . $pointer->id;
				}
			}
		}

		return $gone;
	}

	private function delete(string $key): void
	{
		try {
			$this->client->deleteObject($key);
		} catch (\Throwable $e) {
			$this->logger->error("S3 Uploads: could not delete a file from the bucket ({$this->describe($e)}). The next prune removes it.");
		}
	}

	/** @param array<string,mixed> $settings */
	private function folder(array $settings): ?string
	{
		$folder = $settings['folder'] ?? null;

		return is_string($folder) && trim($folder) !== '' ? $folder : null;
	}

	/**
	 * @param array<string,mixed> $payload
	 *
	 * @return array{string, string, mixed, mixed} collection, id, object, previous
	 */
	private function read(array $payload): array
	{
		return [
			is_string($payload['collection'] ?? null) ? $payload['collection'] : '',
			is_string($payload['id'] ?? null) ? $payload['id'] : '',
			$payload['object'] ?? null,
			$payload['previous'] ?? null,
		];
	}

	/**
	 * Run $work; log anything it throws instead of letting it reach the save.
	 * With no bucket configured there is nothing to keep in step, so nothing runs.
	 */
	private function guard(string $doing, \Closure $work): void
	{
		if (!$this->settings->isConfigured()) {
			return;
		}

		try {
			$work();
		} catch (\Throwable $e) {
			$this->logger->error("S3 Uploads: {$doing} failed ({$this->describe($e)}).");
		}
	}

	/** An error for the log. S3 errors carry their code; nothing here includes a URL or a key. */
	private function describe(\Throwable $e): string
	{
		return $e instanceof S3Exception ? $e->s3Code . ': ' . $e->getMessage() : $e->getMessage();
	}
}
