<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JumpStart\Service\Export;

use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\JumpStart\Data\JumpStartData;

/**
 * Add the access groups a JumpStart references, so a site whose collections
 * and pages use groups does not import without them.
 *
 * Only referenced groups travel, never the whole file:
 *   - collections: `groups` (file access), `registrationGroups`
 *   - objects: `accessGroups` (Site Builder pages), `groups` (auth users)
 * Ids that are not groups on this site are dropped, which also makes a
 * custom field that happens to be called `groups` harmless. The built-in
 * groups are left out: every install has its own.
 */
final readonly class AccessGroupExport
{
	private const BUILT_IN = ['admin', 'default', 'editor', 'viewer'];

	private const COLLECTION_KEYS = ['groups', 'registrationGroups'];
	private const OBJECT_KEYS     = ['accessGroups', 'groups'];

	public function __construct(
		private AccessGroupLister $lister,
	) {
	}

	public function addReferenced(JumpStartData $jumpstart): void
	{
		$ids = array_diff($this->referencedIds($jumpstart), self::BUILT_IN);
		sort($ids);

		foreach ($ids as $id) {
			$group = $this->lister->findById($id);
			if ($group instanceof AccessGroupData) {
				$jumpstart->addAccessGroup($group->toArray());
			}
		}
	}

	/** @return list<string> */
	private function referencedIds(JumpStartData $jumpstart): array
	{
		$ids = [];

		foreach (['reserved', 'custom'] as $kind) {
			foreach ((array)($jumpstart->collections[$kind] ?? []) as $collection) {
				if (is_array($collection)) {
					$ids = [...$ids, ...$this->stringsUnder($collection, self::COLLECTION_KEYS)];
				}
			}
		}

		foreach ($jumpstart->objects as $object) {
			if (is_array($object['data'] ?? null)) {
				$ids = [...$ids, ...$this->stringsUnder($object['data'], self::OBJECT_KEYS)];
			}
		}

		return array_values(array_unique($ids));
	}

	/**
	 * @param array<string,mixed> $data
	 * @param list<string>        $keys
	 *
	 * @return list<string>
	 */
	private function stringsUnder(array $data, array $keys): array
	{
		$strings = [];
		foreach ($keys as $key) {
			foreach ((array)($data[$key] ?? []) as $value) {
				if (is_string($value) && $value !== '') {
					$strings[] = $value;
				}
			}
		}

		return $strings;
	}
}
