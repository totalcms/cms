<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Action\Object\Support\PrivilegedFieldGuard;
use TotalCMS\Domain\Collection\Service\OwnerPropertyResolver;
use TotalCMS\Domain\Object\Service\ObjectFactory;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * Server-side authorization for fields a non-super-admin may not set.
 *
 * The object-write path ({@see ObjectFactory}) copies every schema field from
 * the request with no privilege filtering, so a non-admin could otherwise
 * self-assign `groups:['admin']`, flip a moderation flag, or claim another
 * member's object. This policy is the single source of truth for which fields
 * only a super-admin may set on ANY collection, and how to neutralize an
 * unauthorized write. Three sources feed it:
 *
 *   1. {@see self::PRIVILEGED_FIELDS} — the hardcoded user-record fields, on
 *      auth-type schemas only (schema `auth` or one inheriting from it).
 *   2. Schema `protected` flags — `"protected": true` makes a property
 *      admin-only on any collection; `"protected": {"value": X}` additionally
 *      forces the property to X on every non-admin write, sent or not.
 *   3. The collection's `ownerProperty` — stamped with the actor's user id on
 *      create, reverted on update, never writable through property routes.
 *
 * Applied by {@see PrivilegedFieldGuard} on every object write (save, update,
 * patch, property routes, admin cell edit) and by CollectionAccessMiddleware.
 * The policy resolves no actor itself: the caller passes the actor id and auth
 * collection in, resolved once per request by BaseAccessMiddleware as an
 * `AccessActor` request attribute (an empty actor for anonymous public
 * submissions).
 */
// Not `readonly`: the per-request rules memo below is mutable state.
final class AuthFieldPolicy
{
	/**
	 * Fields a non-super-admin may never set on a collection whose schema
	 * defines them. Confirmed against resources/schemas/auth.json.
	 *
	 * Three of these are not permissions but the state permissions are measured
	 * against, and are just as load-bearing:
	 *
	 *   - `loginCount` is the counter `maxLoginCount` is compared to
	 *     (LoginService / PasskeyLoginAction both test
	 *     `loginCount >= maxLoginCount`). Protecting only the ceiling left the
	 *     limit unenforceable: a user at their quota could save their own
	 *     profile with `loginCount: 0` and grant themselves unlimited logins.
	 *   - `lastlogin` and `created` are the audit trail. A writable audit trail
	 *     is not one.
	 *
	 * None of this blocks the legitimate writers, which reach the store through
	 * domain services rather than the guarded Actions: LastLoginUpdateService
	 * patches `lastlogin`/`loginCount` via ObjectPatcher, and `created` is
	 * assigned server-side by DateFieldResetter::resetOnCreateFields() on every
	 * create. On update these revert to the stored value, which also fixes the
	 * benign version of the bug — the admin form PUTs the whole object, so a
	 * profile save used to rewind `loginCount` to whatever was rendered.
	 *
	 * @var list<string>
	 */
	public const PRIVILEGED_FIELDS = ['groups', 'active', 'expiration', 'maxLoginCount', 'passkeys', 'loginCount', 'lastlogin', 'created'];

	/** Schema id whose records are user/auth records. */
	private const AUTH_SCHEMA = 'auth';

	private readonly LoggerInterface $logger;

	/**
	 * Per-request memo of {@see rulesFor()}, keyed by collection. Cleared by
	 * {@see forgetRules()} at the start of every request.
	 *
	 * @var array<string,array{plain: list<string>, forced: array<string,mixed>, owner: string}>
	 */
	private array $rules = [];

	public function __construct(
		private readonly SchemaFetcher $schemaFetcher,
		private readonly UserValidationService $userValidation,
		private readonly ObjectFetcher $objectFetcher,
		private readonly OwnerPropertyResolver $ownerProperties,
		private readonly Config $config,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::Access);
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
		return !$this->config->authEnabled();
	}

	/**
	 * Every field a non-super-admin may not set on this collection: plain
	 * protected fields, forced-value fields and the owner property together.
	 * Empty for an ordinary content collection (policy is a no-op there).
	 *
	 * @return list<string>
	 */
	public function protectedFieldsFor(string $collection): array
	{
		$rules = $this->rulesFor($collection);

		$fields = array_merge($rules['plain'], array_keys($rules['forced']));
		if ($rules['owner'] !== '') {
			$fields[] = $rules['owner'];
		}

		return array_values(array_unique($fields));
	}

	/**
	 * The protection rules the collection's schema and settings declare.
	 *
	 * `plain`  — revert on update, strip on create, log the attempt.
	 * `forced` — property => value, set on every non-admin write.
	 * `owner`  — the collection's owner property, or ''.
	 *
	 * @return array{plain: list<string>, forced: array<string,mixed>, owner: string}
	 */
	private function rulesFor(string $collection): array
	{
		return $this->rules[$collection] ??= $this->buildRules($collection);
	}

	/**
	 * Drop the memoised rules. Called at the start of every request so a
	 * long-lived worker never serves one request's schema to the next.
	 */
	public function forgetRules(): void
	{
		$this->rules = [];
	}

	/**
	 * @return array{plain: list<string>, forced: array<string,mixed>, owner: string}
	 */
	private function buildRules(string $collection): array
	{
		$none = ['plain' => [], 'forced' => [], 'owner' => ''];

		try {
			$schema = $this->schemaFetcher->fetchSchemaForCollection($collection);
		} catch (\Throwable) {
			return $none;
		}

		$plain  = [];
		$forced = [];

		// Only auth-type collections carry the privileged list. A content
		// collection that merely has a field NAMED `active` or `groups` (very
		// common) must never have those treated as privileged.
		if ($this->isAuthSchema($schema)) {
			$plain = array_values(array_intersect(self::PRIVILEGED_FIELDS, array_keys($schema->properties)));
		}

		foreach ($schema->properties as $name => $definition) {
			if (!is_array($definition) || !array_key_exists('protected', $definition)) {
				continue;
			}

			$flag = $definition['protected'];
			if ($flag === true) {
				$plain[] = (string)$name;
			} elseif (is_array($flag) && array_key_exists('value', $flag)) {
				$forced[(string)$name] = $flag['value'];
			}
			// Any other shape is a schema:lint error and ignored here: a
			// misconfigured flag must not take the write path down.
		}

		return [
			'plain'  => array_values(array_unique($plain)),
			'forced' => $forced,
			'owner'  => $this->ownerProperties->resolve($collection),
		];
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
	 * Neutralize unauthorized protected-field writes in an object payload.
	 * Super-admins pass through. For everyone else: plain protected fields are
	 * reverted to their stored values (update) or stripped (create); forced
	 * fields are set to their configured value; the owner property is stamped
	 * with the actor on create and reverted on update.
	 *
	 * $actorCollection is the auth collection the ACTOR actually belongs to
	 * (from their session) — distinct from $collection, which is the
	 * collection being WRITTEN to and may be a different auth collection
	 * entirely (e.g. an operator editing a `members` record).
	 *
	 * @param array<string,mixed> $incoming
	 *
	 * @return array<string,mixed>
	 *
	 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
	 * @SuppressWarnings("PHPMD.NPathComplexity")
	 */
	public function enforce(string $actorId, string $collection, string $objectId, array $incoming, string $actorCollection = ''): array
	{
		$rules = $this->rulesFor($collection);
		if ($this->enforcementSkipped($rules, $actorId, $actorCollection)) {
			return $incoming;
		}

		// Safety net: the access middleware denies first. With the collection
		// record unreadable there is no owner property to stamp.
		if ($rules['owner'] === OwnerPropertyResolver::UNRESOLVED) {
			throw new \RuntimeException(sprintf('Collection "%s" settings could not be read; refusing to write an owned object.', $collection));
		}

		$exists   = $objectId !== '' && $this->objectFetcher->existsObject($collection, $objectId);
		$existing = $exists ? $this->objectFetcher->fetchObject($collection, $objectId)->toArray() : [];

		// On update the owner behaves exactly like a plain protected field:
		// whatever the client sent is reverted to what is stored.
		$plain = $rules['plain'];
		if ($rules['owner'] !== '' && $exists) {
			$plain[] = $rules['owner'];
		}

		[$incoming, $reverted, $stripped] = $this->revertPlain($plain, $incoming, $existing);

		// Forced values are the configured outcome of a non-admin write, not a
		// neutralised attempt, so they are applied silently — and whether or not
		// the client sent the field, so a partial PATCH cannot keep an admin's
		// value alive past an edit nobody re-reviewed.
		foreach ($rules['forced'] as $field => $value) {
			$incoming[$field] = $value;
		}

		// On create the server decides who owns the object. An anonymous public
		// submission (empty actor) stores an empty owner.
		if ($rules['owner'] !== '' && !$exists) {
			$incoming[$rules['owner']] = $actorId;
		}

		$this->logNeutralized($actorId, $collection, $objectId, $reverted, $stripped);

		return $incoming;
	}

	/**
	 * Whether enforcement does nothing for this write: auth is off, the
	 * collection has no rules, or the actor is a super admin.
	 *
	 * @param array{plain: list<string>, forced: array<string,mixed>, owner: string} $rules
	 */
	private function enforcementSkipped(array $rules, string $actorId, string $actorCollection): bool
	{
		if ($this->authDisabled()) {
			return true;
		}
		if ($rules['plain'] === [] && $rules['forced'] === [] && $rules['owner'] === '') {
			return true;
		}

		return $actorId !== '' && $this->userValidation->isSuperAdmin($actorId, $actorCollection);
	}

	/**
	 * Audit-log a neutralized protected write. Silent when nothing changed.
	 *
	 * @param list<string> $reverted
	 * @param list<string> $stripped
	 */
	private function logNeutralized(string $actorId, string $collection, string $objectId, array $reverted, array $stripped): void
	{
		if ($reverted === [] && $stripped === []) {
			return;
		}

		// The write is neutralized silently as far as the client is concerned
		// — returning an error would confirm to a prober which fields are
		// privileged, and the request's ordinary fields did save. The log is
		// how an operator finds out afterwards why a change didn't stick.
		// Field names only: `passkeys` values are large and credential-ish.
		$this->logger->warning('Privileged field write neutralized', [
			'actor'      => $actorId === '' ? '(none)' : $actorId,
			'collection' => $collection,
			'object'     => $objectId,
			'reverted'   => $reverted,
			'stripped'   => $stripped,
		]);
	}

	/**
	 * Revert (update) or strip (create) each plain protected field the client
	 * sent. Only a value that differs from what is stored counts as an attempt.
	 *
	 * @param list<string>        $plain
	 * @param array<string,mixed> $incoming
	 * @param array<string,mixed> $existing
	 *
	 * @return array{0: array<string,mixed>, 1: list<string>, 2: list<string>} incoming, reverted, stripped
	 */
	private function revertPlain(array $plain, array $incoming, array $existing): array
	{
		$reverted = [];
		$stripped = [];

		foreach ($plain as $field) {
			if (!array_key_exists($field, $incoming)) {
				continue;
			}

			if (array_key_exists($field, $existing)) {
				// The admin form PUTs the whole object, so protected fields ride
				// along on every save whether or not anyone touched them. Only a
				// value that differs from what is stored is an attempted write —
				// without this check the audit log would fire on every save by a
				// non-admin and be worthless.
				if ($incoming[$field] === $existing[$field]) {
					continue;
				}

				$reverted[]       = $field;
				$incoming[$field] = $existing[$field];
			} else {
				$stripped[] = $field;
				unset($incoming[$field]);
			}
		}

		return [$incoming, $reverted, $stripped];
	}

	/**
	 * Remove every protected field from a payload, no actor context. Used by
	 * public registration, which must also positively assign its own defaults
	 * afterwards.
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
	 * property-update routes and the admin cell editor). Super-admins always
	 * may; others may not touch a protected, forced or owner property.
	 *
	 * $actorCollection is the auth collection the ACTOR actually belongs to
	 * — see {@see enforce()}.
	 */
	public function canWriteProperty(string $actorId, string $collection, string $property, string $actorCollection = ''): bool
	{
		if ($this->authDisabled()) {
			return true;
		}
		if (!in_array($property, $this->protectedFieldsFor($collection), true)) {
			return true;
		}

		return $actorId !== '' && $this->userValidation->isSuperAdmin($actorId, $actorCollection);
	}
}
