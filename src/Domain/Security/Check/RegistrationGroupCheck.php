<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Check;

use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\Auth\Service\RegistrationCollections;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Domain\Security\Data\SecurityFinding;
use TotalCMS\Domain\Security\Data\Severity;

/**
 * What a stranger gets by registering. Reads the group ids and the user index
 * only: no email, name or password is ever put in a finding.
 */
class RegistrationGroupCheck implements SecurityCheck
{
	private const ID           = 'registration-group';
	private const DOCS         = 'operations/security#registration-group';
	private const DOCS_NOGROUP = 'auth/access-groups#users-without-a-group';

	public function __construct(
		private readonly CollectionLister $collections,
		private readonly RegistrationCollections $registration,
		private readonly AccessGroupLister $groups,
		private readonly IndexReader $index,
	) {
	}

	public function makesRequests(): bool
	{
		return false;
	}

	public function run(SecurityContext $context): array
	{
		$findings = [];
		$open     = false;

		foreach ($this->collections->listAllCollections() as $collection) {
			if (!$this->registration->isEnabledFor($collection)) {
				continue;
			}
			$open      = true;
			$findings = [...$findings, ...$this->checkCollection($collection)];
		}

		if (!$open) {
			return [SecurityFinding::ok(self::ID, 'Public registration is off')];
		}
		if ($findings === []) {
			return [SecurityFinding::ok(self::ID, 'Public registration only grants limited access')];
		}

		return $findings;
	}

	/** @return list<SecurityFinding> */
	private function checkCollection(CollectionData $collection): array
	{
		$ids = $this->registration->groupsFor($collection->id);
		if ($ids === []) {
			return [$this->noGroup($collection)];
		}

		$writes = false;
		foreach ($ids as $id) {
			if ($id === UserValidationService::ADMINGROUP) {
				return [$this->critical($collection, $id)];
			}
			$group = $this->groups->findById($id);
			if (!$group instanceof AccessGroupData) {
				continue;
			}
			if ($this->isAdminCapable($group)) {
				return [$this->critical($collection, $id)];
			}
			$writes = $writes || $this->canWriteCollections($group);
		}

		if (!$writes) {
			return [];
		}

		return [SecurityFinding::found(
			self::ID,
			Severity::Warning,
			'Registration grants write access',
			sprintf('Visitors who register in "%s" join a group that can create, update or delete collection content.', $collection->id),
			'Give the registration group read access only, or protect the form with a CAPTCHA, a rate limit or email verification.',
			self::DOCS,
		)];
	}

	private function critical(CollectionData $collection, string $group): SecurityFinding
	{
		return SecurityFinding::found(
			self::ID,
			Severity::Critical,
			'Registration grants administrative access',
			sprintf('Visitors who register in "%s" join the "%s" group, which can manage utilities, schemas or access groups.', $collection->id, $group),
			'Remove that group from `auth.publicRegistrationGroup` and the collection\'s registration groups. Use a group with limited permissions.',
			self::DOCS,
		);
	}

	private function noGroup(CollectionData $collection): SecurityFinding
	{
		$count = 0;
		foreach ($this->index->fetchIndex($collection->id)->objects as $row) {
			if (empty($row['groups'])) {
				$count++;
			}
		}

		return SecurityFinding::found(
			self::ID,
			Severity::Info,
			'Registration gives new members no group',
			sprintf('Registration in "%s" assigns no access group. %d existing members have no group.', $collection->id, $count),
			'Assign a registration group with the access you want members to have.',
			self::DOCS_NOGROUP,
		);
	}

	private function isAdminCapable(AccessGroupData $group): bool
	{
		$utils = (array)($group->permissions['utils'] ?? []);
		if (!empty($utils['all']) || !empty($utils['allowed'])) {
			return true;
		}

		// Template editing, or any change to collection settings (a registrant could open registration elsewhere or change registrationGroups).
		if (($group->permissions['builder'] ?? false) === true) {
			return true;
		}

		return $this->grantsAnyTarget($group, 'schemas', ['create', 'update'])
			|| $this->grantsAnyTarget($group, 'collectionsMeta', ['create', 'update', 'delete']);
	}

	private function canWriteCollections(AccessGroupData $group): bool
	{
		return $this->grantsAnyTarget($group, 'collections', ['create', 'update', 'delete']);
	}

	/**
	 * Re-derives AccessGroupData::allowsTargeted (all, or a non-empty allowed list, plus an operation); keep in step with it.
	 *
	 * @param list<string> $operations
	 */
	private function grantsAnyTarget(AccessGroupData $group, string $key, array $operations): bool
	{
		$permission = (array)($group->permissions[$key] ?? []);
		if (empty($permission['all']) && empty($permission['allowed'])) {
			return false;
		}

		return array_intersect($operations, (array)($permission['operations'] ?? [])) !== [];
	}
}
