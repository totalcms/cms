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

	public function id(): string
	{
		return self::ID;
	}

	public function title(): string
	{
		return 'Public registration';
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
				return [$this->critical($collection, $id, ['is the built-in administrator group'])];
			}
			$group = $this->groups->findById($id);
			if (!$group instanceof AccessGroupData) {
				continue;
			}
			$reasons = $this->adminReasons($group);
			if ($reasons !== []) {
				return [$this->critical($collection, $id, $reasons)];
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

	/** @param list<string> $reasons */
	private function critical(CollectionData $collection, string $group, array $reasons): SecurityFinding
	{
		return SecurityFinding::found(
			self::ID,
			Severity::Critical,
			'Registration grants administrative access',
			sprintf('Visitors who register in "%s" join the "%s" group, which %s.', $collection->id, $group, implode(', ', $reasons)),
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

	/**
	 * What makes a group admin-capable, in words for the finding; empty when nothing does.
	 *
	 * @return list<string>
	 */
	private function adminReasons(AccessGroupData $group): array
	{
		$reasons = [];
		$utils   = (array)($group->permissions['utils'] ?? []);
		if (!empty($utils['all'])) {
			$reasons[] = 'can open every admin utility';
		} elseif (!empty($utils['allowed'])) {
			$reasons[] = 'can open the admin utilities ' . implode(', ', array_map(strval(...), (array)$utils['allowed']));
		}
		// Template editing runs Twig on the server.
		if (($group->permissions['builder'] ?? false) === true) {
			$reasons[] = 'can edit Site Builder templates';
		}
		if ($this->grantsAnyTarget($group, 'schemas', ['create', 'update'])) {
			$reasons[] = 'can create or change schemas';
		}
		// A registrant could open registration elsewhere or change registrationGroups.
		if ($this->grantsAnyTarget($group, 'collectionsMeta', ['create', 'update', 'delete'])) {
			$reasons[] = 'can change collection settings';
		}

		return $reasons;
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
