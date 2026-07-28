<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Service;

use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Support\Config;

/**
 * Server-side authorization for privileged user-record fields.
 *
 * The object-write path ({@see \TotalCMS\Domain\Object\Service\ObjectFactory})
 * copies every schema field from the request with no privilege filtering, so a
 * non-admin could otherwise self-assign `groups:['admin']` (escalation) or a
 * public registrant could submit it (mass-assignment). This policy is the single
 * source of truth for which fields only a super-admin may set on an auth-type
 * collection, and how to neutralize an unauthorized write.
 */
final readonly class AuthFieldPolicy
{
	/**
	 * Fields a non-super-admin may never set on a collection whose schema
	 * defines them. Confirmed against resources/schemas/auth.json.
	 *
	 * @var list<string>
	 */
	public const PRIVILEGED_FIELDS = ['groups', 'active', 'expiration', 'maxLoginCount', 'passkeys'];

	/** Schema id whose records are user/auth records. */
	private const AUTH_SCHEMA = 'auth';

	public function __construct(
		private SchemaFetcher $schemaFetcher,
		private UserValidationService $userValidation,
		private ObjectFetcher $objectFetcher,
		private Config $config,
	) {
	}

	/**
	 * Whether authentication is switched off for the whole install.
	 *
	 * In that mode both auth middlewares pass every request straight through
	 * (AuthMiddleware / DualAuthMiddleware), no session user can resolve as a
	 * super-admin, and the admin is open to anyone who can reach it. Field-level
	 * authz therefore protects nothing — it only makes privileged fields
	 * permanently unwritable. BaseAccessMiddleware and
	 * SystemCollectionGuardMiddleware already stand down here; this matches them.
	 */
	private function authDisabled(): bool
	{
		return ($this->config->auth['enable'] ?? true) === false;
	}

	/**
	 * The privileged fields the given collection's schema actually defines.
	 * Empty for ordinary content collections (policy is a no-op there).
	 *
	 * @return list<string>
	 */
	public function protectedFieldsFor(string $collection): array
	{
		try {
			$schema = $this->schemaFetcher->fetchSchemaForCollection($collection);
		} catch (\Throwable) {
			return [];
		}

		// Only auth-type collections carry privileged fields. A content
		// collection that merely has a field NAMED `active` or `groups` (very
		// common) must never have those treated as privileged.
		if (!$this->isAuthSchema($schema)) {
			return [];
		}

		$present = array_keys($schema->properties);

		return array_values(array_intersect(self::PRIVILEGED_FIELDS, $present));
	}

	/**
	 * Whether the schema is the `auth` schema or inherits from it. Inheritance
	 * is resolved one level deep by SchemaFetcher (matching its own model), and
	 * `inheritFrom` is retained on the flattened schema.
	 */
	private function isAuthSchema(SchemaData $schema): bool
	{
		return $schema->id === self::AUTH_SCHEMA || in_array(self::AUTH_SCHEMA, $schema->inheritFrom, true);
	}

	/**
	 * Neutralize unauthorized privileged-field writes in a full-object payload.
	 * Super-admins pass through. For everyone else, privileged fields are
	 * reverted to their stored values (update) or stripped (create).
	 *
	 * @param array<string,mixed> $incoming
	 *
	 * @return array<string,mixed>
	 */
	public function enforce(string $actorId, string $collection, string $objectId, array $incoming): array
	{
		if ($this->authDisabled()) {
			return $incoming;
		}

		$protected = $this->protectedFieldsFor($collection);
		if ($protected === []) {
			return $incoming;
		}
		if ($actorId !== '' && $this->userValidation->isSuperAdmin($actorId)) {
			return $incoming;
		}

		$existing = [];
		if ($objectId !== '' && $this->objectFetcher->existsObject($collection, $objectId)) {
			$existing = $this->objectFetcher->fetchObject($collection, $objectId)->toArray();
		}

		foreach ($protected as $field) {
			if (!array_key_exists($field, $incoming)) {
				continue;
			}
			if (array_key_exists($field, $existing)) {
				$incoming[$field] = $existing[$field];
			} else {
				unset($incoming[$field]);
			}
		}

		return $incoming;
	}

	/**
	 * Remove every schema-present privileged field from a payload, no actor
	 * context. Used by public registration, which must also positively assign
	 * its own defaults afterwards.
	 *
	 * Deliberately NOT relaxed when auth is disabled, unlike the actor-based
	 * methods above. This one guards an endpoint anonymous callers can reach, so
	 * there is no actor to trust either way; letting it through would bake
	 * `groups:['admin']` into records that become live escalations the moment
	 * auth is switched back on.
	 *
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	public function stripProtected(string $collection, array $data): array
	{
		foreach ($this->protectedFieldsFor($collection) as $field) {
			unset($data[$field]);
		}

		return $data;
	}

	/**
	 * Whether the actor may write the given single property (for the
	 * property-update routes). Super-admins always may; others may not touch a
	 * privileged property.
	 */
	public function canWriteProperty(string $actorId, string $collection, string $property): bool
	{
		if ($this->authDisabled()) {
			return true;
		}
		if (!in_array($property, $this->protectedFieldsFor($collection), true)) {
			return true;
		}

		return $actorId !== '' && $this->userValidation->isSuperAdmin($actorId);
	}
}
