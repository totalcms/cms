<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Collection\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Answers "which property owns the objects of this collection?" for the two
 * places that enforce ownership: CollectionAccessMiddleware (scoping reads and
 * writes to the actor's own objects) and AuthFieldPolicy (stamping the owner on
 * create, reverting it on update).
 *
 * A collection is owned when its `ownerProperty` setting names a property the
 * flattened schema defines. An `ownerProperty` the schema lacks is treated as
 * unset — enforcing it would deny every object (none carries the property)
 * while stamping nothing. `schema:lint` is where that misconfiguration should
 * eventually be reported.
 *
 * Not final so unit tests can mock it; readonly like its collaborators.
 */
readonly class OwnerPropertyResolver
{
	/**
	 * Returned when the collection record cannot be read. No schema can
	 * contain this property name, so every comparison against it fails closed:
	 * no object matches, no list item matches, and MCP treats the collection
	 * as owned and admin-only.
	 */
	public const UNRESOLVED = "\0owner-unresolved";

	public function __construct(
		private CollectionFetcher $collectionFetcher,
		private SchemaFetcher $schemaFetcher,
	) {
	}

	/**
	 * The owner property name, or '' when the collection is not owned or does
	 * not exist. Never throws: this runs inside access middleware, where a
	 * storage error must not turn into a 500 that leaks more than a denial
	 * would.
	 *
	 * Fails closed on a collection record that cannot be read: the configured
	 * name is unknowable, so {@see self::UNRESOLVED} is returned and every
	 * owner check against it denies. Fails closed on a schema that cannot be
	 * loaded too: the configured name is returned, so the middleware enforces
	 * ownership against a property it cannot confirm — which denies — instead
	 * of treating an owned collection as a plain one.
	 */
	public function resolve(string $collection): string
	{
		try {
			$meta = $this->collectionFetcher->fetchCollection($collection);
		} catch (\Throwable) {
			return self::UNRESOLVED;
		}
		if (!$meta instanceof CollectionData || $meta->ownerProperty === '') {
			return '';
		}

		try {
			$schema = $this->schemaFetcher->fetchSchema($meta->schema);
		} catch (\Throwable) {
			return $meta->ownerProperty;
		}

		return array_key_exists($meta->ownerProperty, $schema->properties) ? $meta->ownerProperty : '';
	}

	/**
	 * The auth collection whose members are this collection's scoped owners,
	 * or '' when any auth collection's members are (the setting is unset), the
	 * collection does not exist, or its record cannot be read. Never throws.
	 *
	 * The unreadable case needs no denial of its own: {@see self::resolve()}
	 * returns {@see self::UNRESOLVED} for the same record, and every owner
	 * check already denies on that.
	 */
	public function ownerCollection(string $collection): string
	{
		try {
			$meta = $this->collectionFetcher->fetchCollection($collection);
		} catch (\Throwable) {
			return '';
		}

		return $meta instanceof CollectionData ? $meta->ownerCollection : '';
	}

	/**
	 * Whether the actor is an outsider here: the collection names an
	 * `ownerCollection` and the actor belongs to a different auth collection.
	 * Outsiders own nothing in the collection. An empty `$actorCollection` (a
	 * login at the plain /admin/login route) is the default auth collection.
	 * The one rule for the access middleware, the field policy and the admin
	 * form.
	 */
	public function isOutsider(string $collection, string $actorCollection, string $defaultCollection): bool
	{
		$required = $this->ownerCollection($collection);
		if ($required === '') {
			return false;
		}

		return ($actorCollection !== '' ? $actorCollection : $defaultCollection) !== $required;
	}
}
