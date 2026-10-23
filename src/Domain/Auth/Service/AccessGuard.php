<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Data\Decision;
use TotalCMS\Domain\Auth\Data\Operation;
use TotalCMS\Domain\Auth\Data\ReadScope;
use TotalCMS\Domain\Collection\Data\OwnerScope;
use TotalCMS\Domain\Collection\Service\OwnerPropertyKind;
use TotalCMS\Domain\Collection\Service\OwnerPropertyResolver;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectWriteGate;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * The single access decision for collection content, made for an
 * {@see Actor} rather than for a request, so REST, MCP, forms, Twig and the
 * storage layer all apply the same rules.
 *
 * Three layers, always in this order, and a later one never grants what an
 * earlier one refused:
 *
 *   1. **Groups** ({@see groupAllows()}): the actor's access groups must grant
 *      the operation on the collection.
 *   2. **Ownership** ({@see ownerDecision()}, {@see ReadScope()}): on an owned
 *      collection (its `ownerProperty` names a schema property) everyone but a
 *      trusted actor is limited to the objects they own.
 *   3. **Field policy** ({@see applyWrite()}, {@see canWriteProperty()}): which
 *      fields a non-trusted actor may set, from three sources:
 *        - {@see self::PRIVILEGED_FIELDS}, the user-record fields, on auth-type
 *          schemas only (schema `auth` or one inheriting it);
 *        - schema `protected` flags: `"protected": true` is admin-only on any
 *          collection; `"protected": {"value": X}` also forces X on every
 *          non-trusted write, sent or not;
 *        - the collection's `ownerProperty`: stamped with the actor on create
 *          (or `[actorId]` for a list property; empty for anonymous or an actor
 *          outside the `ownerCollection`), reverted on update.
 *
 * Trusted actors (system, API keys, admins) pass every layer. With
 * authentication switched off for the install there is no actor to trust or
 * distrust, so every layer stands down, as the access middleware does.
 *
 * The storage layer applies layers 2 and 3 on every write through
 * {@see ObjectWriteGate}; entry points ask the
 * guard first so they can answer with a proper refusal.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") the guard is the one place
 *                                                every entry point asks;
 *                                                splitting it would bring back
 *                                                a copy of the rules per surface
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity") for the same reason: the
 *                                                rules are one policy, and its
 *                                                branches are the rules
 */
// Not `readonly`: the per-request rules memo below is mutable state.
final class AccessGuard
{
	/**
	 * Fields a non-trusted actor may never set on a collection whose schema
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
	 * The legitimate writers run as the system actor: LastLoginUpdateService
	 * elevates for `lastlogin`/`loginCount`, and `created` is assigned
	 * server-side by DateFieldResetter::resetOnCreateFields() on every create.
	 * On update these revert to the stored value, which also fixes the benign
	 * version of the bug — the admin form PUTs the whole object, so a profile
	 * save used to rewind `loginCount` to whatever was rendered.
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
	 * @var array<string,array{plain: list<string>, forced: array<string,mixed>, owner: string, ownerIsList: bool}>
	 */
	private array $rules = [];

	public function __construct(
		private readonly SchemaFetcher $schemaFetcher,
		private readonly ObjectFetcher $objectFetcher,
		private readonly OwnerPropertyResolver $ownerProperties,
		private readonly Config $config,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::Access);
	}

	// ─── Layer 1: groups ───────────────────────────────────────────────────

	/**
	 * Whether the actor's access groups grant $operation (read, create,
	 * update, delete) on $collection, or on collections in general when
	 * $collection is ''.
	 *
	 * A session user may always update their own record in their own auth
	 * collection (a profile save). A bearer may not: the token is the
	 * credential, and the record it would update holds `groups`.
	 */
	public function groupAllows(Actor $actor, string $collection, string $operation, string $objectId = ''): bool
	{
		if ($this->authDisabled()) {
			return true;
		}

		// An API key is trusted within the collections its paths cover.
		if ($actor->isTrusted()) {
			return $actor->reaches($collection, $operation);
		}

		if (!$actor->isBearer() && $operation === 'update' && $collection !== '' && $objectId !== ''
			&& $collection === $actor->authCollection && $objectId === $actor->userId) {
			return true;
		}

		return $collection !== ''
			? $actor->authority()->canCollection($operation, $collection)
			: $actor->authority()->canCollectionsOperation($operation);
	}

	/**
	 * The full decision for an operation on a collection or one of its
	 * objects: groups, then ownership, then the field-policy refusals for
	 * operations that copy values in bulk.
	 */
	public function can(Actor $actor, string $collection, Operation $operation, string $objectId = ''): Decision
	{
		if (!$this->groupAllows($actor, $collection, $operation->groupOperation(), $objectId)) {
			return Decision::DenyGroup;
		}

		$owner = $this->ownerDecision($actor, $collection, $operation, $objectId);
		if (!$owner->passes()) {
			return $owner;
		}

		return $this->copyAllows($actor, $collection, $operation) ? $owner : Decision::DenyProtected;
	}

	// ─── Layer 2: ownership ────────────────────────────────────────────────

	/**
	 * The owner decision on an owned collection; Allow on any other.
	 *
	 *   - an actor with an empty id, or one from an auth collection other than
	 *     the collection's `ownerCollection`, owns nothing and is denied;
	 *   - an operation that cannot be scoped ({@see Operation::isUnscopable()})
	 *     is denied;
	 *   - without an object id only Create and List pass;
	 *   - a missing object is NotFound for a read (the entry point answers its
	 *     usual 404) and denied otherwise: a property route would create it as
	 *     a side effect of an upload, squatting the id;
	 *   - otherwise the object's owner must be the actor.
	 */
	public function ownerDecision(Actor $actor, string $collection, Operation $operation, string $objectId = ''): Decision
	{
		if ($actor->isTrusted() || $this->authDisabled() || $collection === '') {
			return Decision::Allow;
		}

		$ownerProperty = $this->ownerProperties->resolve($collection);
		if ($ownerProperty === '') {
			return Decision::Allow;
		}

		if ($ownerProperty === OwnerPropertyResolver::UNRESOLVED || $actor->userId === '' || $this->isOutsider($actor, $collection)) {
			return Decision::DenyOwner;
		}

		if ($operation->isUnscopable()) {
			return Decision::DenyOwner;
		}

		if ($objectId === '') {
			return $operation === Operation::Create || $operation === Operation::List ? Decision::Allow : Decision::DenyOwner;
		}

		if (!$this->objectFetcher->existsObject($collection, $objectId)) {
			return $operation === Operation::Read ? Decision::NotFound : Decision::DenyOwner;
		}

		$object = $this->objectFetcher->fetchObject($collection, $objectId)->toArray();

		return (new OwnerScope($ownerProperty, $actor->userId))->matches($object) ? Decision::Allow : Decision::DenyOwner;
	}

	/**
	 * Which objects of $collection the actor may read ({@see ReadScope}).
	 * $publicRead says whether the surface asking makes the collection
	 * readable without signing in (MCP `mcp.access: public`); others'
	 * published objects are then visible too, so signing in never shows a
	 * member less than an anonymous visitor sees.
	 *
	 *   - trusted actors: everything (an API key: within the collections its
	 *     paths cover);
	 *   - no group grant to read: published objects when $publicRead, else none;
	 *   - a grant on an unowned collection: everything, drafts included;
	 *   - a grant on an owned collection: their own objects, drafts included,
	 *     plus others' published ones when $publicRead. An actor who can own
	 *     nothing there (no id, outside the `ownerCollection`, or an owner
	 *     property that no longer resolves) is treated as having no grant.
	 *
	 * Unlike the other layers it does not stand down when the install switches
	 * authentication off: MCP authenticates its callers itself, so an
	 * anonymous MCP caller must still not see drafts. REST asks only while
	 * authentication is on (the access middleware is skipped otherwise).
	 */
	public function readScope(Actor $actor, string $collection, bool $publicRead = false): ReadScope
	{
		$others = $publicRead ? ReadScope::publishedOnly() : ReadScope::none();
		if ($actor->isTrusted()) {
			return $actor->reaches($collection, 'read') ? ReadScope::all() : $others;
		}

		if ($collection === '' || !$actor->authority()->canCollection('read', $collection)) {
			return $others;
		}

		$ownerProperty = $this->ownerProperties->resolve($collection);
		if ($ownerProperty === '') {
			return ReadScope::all();
		}

		if ($ownerProperty === OwnerPropertyResolver::UNRESOLVED || $actor->userId === '' || $this->isOutsider($actor, $collection)) {
			return $others;
		}

		return new ReadScope(new OwnerScope($ownerProperty, $actor->userId), published: $publicRead, drafts: false);
	}

	/**
	 * Whether the collection names an `ownerCollection` and the actor belongs
	 * to a different auth collection.
	 */
	private function isOutsider(Actor $actor, string $collection): bool
	{
		$default = $this->defaultCollection();

		return $this->ownerProperties->isOutsider($collection, $actor->authCollection !== '' ? $actor->authCollection : $default, $default);
	}

	// ─── Layer 3: field policy ─────────────────────────────────────────────

	/**
	 * Clone and import copy whole objects past the field policy, so a
	 * non-trusted actor is refused them on any collection with a protected,
	 * forced or owner rule. True for every other operation.
	 */
	public function copyAllows(Actor $actor, string $collection, Operation $operation): bool
	{
		if ($operation !== Operation::Clone && $operation !== Operation::Import) {
			return true;
		}

		return $actor->isTrusted() || $this->authDisabled() || $this->protectedFieldsFor($collection) === [];
	}

	/**
	 * Every field a non-trusted actor may not set on this collection: plain
	 * protected fields, forced-value fields and the owner property together.
	 * Empty for an ordinary content collection (the policy is a no-op there).
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
	 * Whether the actor may write the given single property (the property
	 * routes and the admin cell editor). Trusted actors always may; others may
	 * not touch a protected, forced or owner property.
	 */
	public function canWriteProperty(Actor $actor, string $collection, string $property): bool
	{
		if ($actor->isTrusted() || $this->authDisabled()) {
			return true;
		}

		return !in_array($property, $this->protectedFieldsFor($collection), true);
	}

	/**
	 * Neutralize what the actor may not write in an object payload. Trusted
	 * actors pass through. For everyone else: plain protected fields are
	 * reverted to their stored values (update) or stripped (create); forced
	 * fields are set to their configured value; the owner property is stamped
	 * with the actor on create (or `[actorId]` for a list property; empty for
	 * anonymous or an actor outside the collection's `ownerCollection`) and
	 * reverted on update.
	 *
	 * @param array<string,mixed> $incoming
	 *
	 * @return array<string,mixed>
	 */
	public function applyWrite(Actor $actor, string $collection, string $objectId, array $incoming): array
	{
		if ($actor->isTrusted() || $this->authDisabled()) {
			return $incoming;
		}

		$rules = $this->rulesFor($collection);
		if ($this->enforcementSkipped($rules, $actor)) {
			return $incoming;
		}

		// Safety net: the entry point denies first. With the collection record
		// unreadable there is no owner property to stamp.
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

		// Forced values are the configured outcome of a non-trusted write, not a
		// neutralised attempt, so they are applied silently — and whether or not
		// the client sent the field, so a partial PATCH cannot keep an admin's
		// value alive past an edit nobody re-reviewed.
		foreach ($rules['forced'] as $field => $value) {
			$incoming[$field] = $value;
		}

		// On create the server decides who owns the object. Anonymous stores an
		// empty owner: '' or, for a list owner property, []. So does a member of
		// an auth collection other than the one `ownerCollection` names: they
		// own nothing here.
		if ($rules['owner'] !== '' && !$exists) {
			$stampId                   = $this->isOutsider($actor, $collection) ? '' : $actor->userId;
			$incoming[$rules['owner']] = $rules['ownerIsList']
				? ($stampId === '' ? [] : [$stampId])
				: $stampId;
		}

		$this->logNeutralized($actor, $collection, $objectId, $reverted, $stripped);

		return $incoming;
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
	 * Drop the memoised rules. Called at the start of every request so a
	 * long-lived worker never serves one request's schema to the next.
	 */
	public function forgetRules(): void
	{
		$this->rules = [];
	}

	/**
	 * The protection rules the collection's schema and settings declare.
	 *
	 * `plain`  — revert on update, strip on create, log the attempt.
	 * `forced` — property => value, set on every non-trusted write.
	 * `owner`  — the collection's owner property, or ''.
	 * `ownerIsList` — whether the owner property is a list of strings.
	 *
	 * @return array{plain: list<string>, forced: array<string,mixed>, owner: string, ownerIsList: bool}
	 */
	private function rulesFor(string $collection): array
	{
		return $this->rules[$collection] ??= $this->buildRules($collection);
	}

	/**
	 * @return array{plain: list<string>, forced: array<string,mixed>, owner: string, ownerIsList: bool}
	 */
	private function buildRules(string $collection): array
	{
		$none = ['plain' => [], 'forced' => [], 'owner' => '', 'ownerIsList' => false];

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

		$owner = $this->ownerProperties->resolve($collection);

		return [
			'plain'       => array_values(array_unique($plain)),
			'forced'      => $forced,
			'owner'       => $owner,
			'ownerIsList' => $this->isListProperty($schema, $owner),
		];
	}

	/**
	 * Whether the named property is a list of strings (see OwnerPropertyKind).
	 * False for no owner and for the unresolved sentinel, which name no schema
	 * property.
	 */
	private function isListProperty(SchemaData $schema, string $property): bool
	{
		if ($property === '' || $property === OwnerPropertyResolver::UNRESOLVED) {
			return false;
		}

		$definition = $schema->properties[$property] ?? [];

		return is_array($definition) && OwnerPropertyKind::of($definition) === OwnerPropertyKind::LIST;
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
	 * Whether enforcement does nothing for this write: auth is off, the
	 * collection has no rules, or the actor is trusted.
	 *
	 * @param array{plain: list<string>, forced: array<string,mixed>, owner: string, ownerIsList: bool} $rules
	 */
	private function enforcementSkipped(array $rules, Actor $actor): bool
	{
		if ($actor->isTrusted() || $this->authDisabled()) {
			return true;
		}

		return $rules['plain'] === [] && $rules['forced'] === [] && $rules['owner'] === '';
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
	 * Audit-log a neutralized protected write. Silent when nothing changed.
	 *
	 * @param list<string> $reverted
	 * @param list<string> $stripped
	 */
	private function logNeutralized(Actor $actor, string $collection, string $objectId, array $reverted, array $stripped): void
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
			'actor'      => $actor->userId === '' ? '(none)' : $actor->userId,
			'collection' => $collection,
			'object'     => $objectId,
			'reverted'   => $reverted,
			'stripped'   => $stripped,
		]);
	}

	/**
	 * Whether authentication is switched off for the whole install. Both auth
	 * middlewares then pass every request straight through and the admin is
	 * open to anyone who can reach it, so access rules protect nothing — they
	 * would only make protected fields permanently unwritable.
	 */
	private function authDisabled(): bool
	{
		return !$this->config->authEnabled();
	}

	private function defaultCollection(): string
	{
		return (string)($this->config->auth['collection'] ?? '');
	}
}
