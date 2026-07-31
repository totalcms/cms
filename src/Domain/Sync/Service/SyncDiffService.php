<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Sync\Service;

/**
 * Compares the local and remote sync payloads item by item, so a dry-run can
 * say what would actually CHANGE rather than merely list what would travel.
 *
 * Two independent signals, deliberately separated:
 *
 *  - Content decides WHETHER two copies differ. Items are hashed with their
 *    `updated` timestamp excluded — the timestamp records when a write
 *    happened, not what it wrote, and two identical copies may carry
 *    different stamps (anything written before timestamps were preserved
 *    through sync).
 *
 *  - The `updated` timestamp decides WHICH SIDE is newer, as a hint. When
 *    only one side carries a stamp, that side is reported as likely newer —
 *    an unstamped copy was last written by a version of T3 that didn't
 *    maintain the field. This heuristic can be wrong while one side still
 *    runs such a version (it can keep editing without stamping), which is
 *    why direction is a hint and never a verdict: the hash alone decides
 *    "differs", and the worst a wrong hint costs is a mislabeled direction.
 *
 * Clock skew between the two machines shifts direction hints, not statuses.
 */
final class SyncDiffService
{
	public const SAME        = 'same';
	public const DIFFERS     = 'differs';
	public const LOCAL_ONLY  = 'local-only';
	public const REMOTE_ONLY = 'remote-only';

	/**
	 * @param array<string,mixed> $local  Local sync payload (JumpStart shape)
	 * @param array<string,mixed> $remote Remote sync payload (same shape)
	 *
	 * @return array{
	 *   schemas: array<string,array{status:string,localUpdated:?string,remoteUpdated:?string,newer:?string}>,
	 *   templates: array<string,array{status:string,localUpdated:?string,remoteUpdated:?string,newer:?string}>,
	 *   objects: array<string,array{status:string,localUpdated:?string,remoteUpdated:?string,newer:?string}>,
	 *   collections: array<string,array{status:string,localUpdated:?string,remoteUpdated:?string,newer:?string}>
	 * }
	 */
	public function diff(array $local, array $remote): array
	{
		return [
			'schemas' => $this->diffCategory(
				$this->indexById($local['schemas'] ?? []),
				$this->indexById($remote['schemas'] ?? []),
				fn (array $item): ?string => is_string($item['updated'] ?? null) && $item['updated'] !== '' ? $item['updated'] : null,
				['updated'],
			),
			'collections' => $this->diffCategory(
				$this->indexCollections($local['collections'] ?? []),
				$this->indexCollections($remote['collections'] ?? []),
				fn (array $item): ?string => is_string($item['updated'] ?? null) && $item['updated'] !== '' ? $item['updated'] : null,
				['updated'],
			),
			'templates' => $this->diffCategory(
				$this->indexById($local['templates'] ?? []),
				$this->indexById($remote['templates'] ?? []),
				fn (array $item): ?string => null, // templates carry no timestamp
				[],
			),
			'objects' => $this->diffCategory(
				$this->indexObjects($local['objects'] ?? []),
				$this->indexObjects($remote['objects'] ?? []),
				fn (array $item): ?string => is_string($item['data']['updated'] ?? null) && $item['data']['updated'] !== '' ? $item['data']['updated'] : null,
				['updated'],
			),
		];
	}

	/**
	 * @param array<string,array<string,mixed>>       $local
	 * @param array<string,array<string,mixed>>       $remote
	 * @param callable(array<string,mixed>): ?string  $timestampOf
	 * @param list<string>                            $excludeFromHash keys ignored when comparing content
	 *
	 * @return array<string,array{status:string,localUpdated:?string,remoteUpdated:?string,newer:?string}>
	 */
	private function diffCategory(array $local, array $remote, callable $timestampOf, array $excludeFromHash): array
	{
		$result = [];

		foreach (array_unique([...array_keys($local), ...array_keys($remote)]) as $key) {
			$localItem  = $local[$key] ?? null;
			$remoteItem = $remote[$key] ?? null;

			$localUpdated  = $localItem !== null ? $timestampOf($localItem) : null;
			$remoteUpdated = $remoteItem !== null ? $timestampOf($remoteItem) : null;

			if ($localItem === null) {
				$status = self::REMOTE_ONLY;
			} elseif ($remoteItem === null) {
				$status = self::LOCAL_ONLY;
			} else {
				$status = $this->contentHash($localItem, $excludeFromHash) === $this->contentHash($remoteItem, $excludeFromHash)
					? self::SAME
					: self::DIFFERS;
			}

			$result[$key] = [
				'status'        => $status,
				'localUpdated'  => $localUpdated,
				'remoteUpdated' => $remoteUpdated,
				'newer'         => $status === self::DIFFERS ? $this->newerSide($localUpdated, $remoteUpdated) : null,
			];
		}

		return $result;
	}

	/**
	 * @param array<int,mixed> $items
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function indexById(array $items): array
	{
		$indexed = [];
		foreach ($items as $item) {
			if (is_array($item) && isset($item['id'])) {
				$indexed[(string)$item['id']] = $item;
			}
		}

		return $indexed;
	}

	/**
	 * Flatten the JumpStart collections block ({reserved: [...], custom:
	 * [...]}) into one id-keyed map of settings. Sync exporters emit the
	 * object form for both kinds; a bare-string reserved entry (starter-kit
	 * form, or an older remote) means "defaults" and indexes as a minimal
	 * `{id}` so it still participates in existence comparison.
	 *
	 * @param array<string,mixed> $collections
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function indexCollections(array $collections): array
	{
		$indexed = [];
		foreach (['custom', 'reserved'] as $kind) {
			$entries = $collections[$kind] ?? [];
			if (!is_array($entries)) {
				continue;
			}
			foreach ($entries as $entry) {
				if (is_string($entry) && $entry !== '') {
					$indexed[$entry] ??= ['id' => $entry];
				} elseif (is_array($entry) && isset($entry['id'])) {
					$indexed[(string)$entry['id']] = $entry;
				}
			}
		}

		return $indexed;
	}

	/**
	 * Objects key as "collection/id" — ids are only unique per collection.
	 *
	 * @param array<int,mixed> $items
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function indexObjects(array $items): array
	{
		$indexed = [];
		foreach ($items as $item) {
			if (is_array($item) && isset($item['collection'], $item['id'])) {
				$indexed[$item['collection'] . '/' . $item['id']] = $item;
			}
		}

		return $indexed;
	}

	/**
	 * Content hash with volatile keys removed and keys sorted recursively,
	 * so serialization order can never masquerade as a content difference.
	 *
	 * @param array<string,mixed> $item
	 * @param list<string>        $exclude
	 */
	private function contentHash(array $item, array $exclude): string
	{
		// The exclusion applies wherever the item keeps its payload: at the
		// top level for schemas, inside `data` for objects.
		foreach ($exclude as $key) {
			unset($item[$key]);
			if (isset($item['data']) && is_array($item['data'])) {
				unset($item['data'][$key]);
			}
		}

		$this->ksortRecursive($item);

		return hash('sha256', (string)json_encode($item));
	}

	/** @param array<mixed> $array */
	private function ksortRecursive(array &$array): void
	{
		ksort($array);
		foreach ($array as &$value) {
			if (is_array($value)) {
				$this->ksortRecursive($value);
			}
		}
	}

	/**
	 * 'local' | 'remote' | null. Both stamped → later wins; one stamped →
	 * that side (an unstamped copy predates timestamp maintenance); neither
	 * or unparseable → no hint.
	 */
	private function newerSide(?string $localUpdated, ?string $remoteUpdated): ?string
	{
		if ($localUpdated === null && $remoteUpdated === null) {
			return null;
		}
		if ($remoteUpdated === null) {
			return 'local';
		}
		if ($localUpdated === null) {
			return 'remote';
		}

		$localTime  = strtotime($localUpdated);
		$remoteTime = strtotime($remoteUpdated);
		if ($localTime === false || $remoteTime === false || $localTime === $remoteTime) {
			return null;
		}

		return $localTime > $remoteTime ? 'local' : 'remote';
	}
}
