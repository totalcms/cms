<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Support\Config;

/**
 * Which collections accept public self-registration, and which access groups
 * their registrants receive.
 *
 * A collection is open when it appears in the `auth.publicRegistration` config
 * allow-list OR its own `publicRegistration` toggle is on and its schema is
 * `auth` or inherits it. The config list is the floor: a collection listed
 * there registers whatever its toggle or schema says, and a missing or
 * unreadable collection record never closes it.
 *
 * Groups are additive: the global `auth.publicRegistrationGroup` (when set)
 * plus the collection's `registrationGroups`, never `admin` from the latter.
 * A `groups` value submitted in a registration form is never consulted here.
 */
final readonly class RegistrationCollections
{
	public function __construct(
		private Config $config,
		private CollectionFetcher $collectionFetcher,
		private SchemaFetcher $schemaFetcher,
	) {
	}

	/** The config allow-list OR the collection's own `publicRegistration` toggle on an auth-schema collection. */
	public function isEnabled(string $collection): bool
	{
		if (in_array($collection, $this->configured(), true)) {
			return true;
		}

		$data = $this->fetch($collection);

		return $data instanceof CollectionData && $this->isEnabledFor($data);
	}

	/** isEnabled() for a collection record already in hand: no fetch. */
	public function isEnabledFor(CollectionData $collection): bool
	{
		if (in_array($collection->id, $this->configured(), true)) {
			return true;
		}

		// The toggle only counts on a collection that holds users. The config
		// list above is the floor and never checked the schema.
		return $collection->publicRegistration && $this->schemaFetcher->isOrInherits($collection->schema, 'auth');
	}

	/**
	 * The groups every registrant of $collection receives: the global
	 * `publicRegistrationGroup` (when non-empty) plus the collection's
	 * `registrationGroups` minus `admin`, deduplicated, reindexed.
	 *
	 * @return list<string>
	 */
	public function groupsFor(string $collection): array
	{
		$global = (string)($this->config->auth['publicRegistrationGroup'] ?? '');
		$groups = $global !== '' ? [$global] : [];

		$data = $this->fetch($collection);
		foreach ($data instanceof CollectionData ? $data->registrationGroups : [] as $group) {
			// Stored JSON is untrusted: the property is typed list<string>, a hand-edited record may not be.
			// Never `admin`: registrationGroups is writable by any collectionsMeta
			// grantee, so honouring it would let one open registration and sign up
			// anonymously as a super admin. The global group is super-admin-set.
			if (is_string($group) && $group !== '' && $group !== UserValidationService::ADMINGROUP) { // @phpstan-ignore function.alreadyNarrowedType
				$groups[] = $group;
			}
		}

		return array_values(array_unique($groups));
	}

	/** @return list<string> */
	private function configured(): array
	{
		$ids = [];
		foreach ((array)($this->config->auth['publicRegistration'] ?? []) as $id) {
			if (is_string($id) && $id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/** A missing collection, or one whose record cannot be read, counts as toggle off and no groups. */
	private function fetch(string $collection): ?CollectionData
	{
		try {
			return $this->collectionFetcher->fetchCollection($collection);
		} catch (\Throwable) {
			return null;
		}
	}
}
