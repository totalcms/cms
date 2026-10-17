<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Auth\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Auth\Service\AuthFieldPolicy;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Collection\Service\OwnerPropertyResolver;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

final class AuthFieldPolicyTest extends TestCase
{
	private LoggerInterface $logger;

	protected function setUp(): void
	{
		$this->logger = $this->makeLogger();
	}

	/** @return list<array{level:mixed,message:string,context:array<string,mixed>}> */
	private function logRecords(): array
	{
		return $this->logger->records; // @phpstan-ignore-line anonymous class property
	}

	/**
	 * @param list<string>                     $props       schema property names (empty definitions)
	 * @param array<string,array<string,mixed>> $definitions property definitions merged over $props (e.g. ['draft' => ['protected' => ['value' => true]]])
	 * @param list<string>                     $inheritFrom
	 * @param array<string,mixed>|null          $existing    stored record, or null when it doesn't exist
	 */
	private function makePolicy(bool $isSuperAdmin, ?array $existing = null, array $props = ['groups', 'active', 'expiration', 'maxLoginCount', 'passkeys', 'loginCount', 'lastlogin', 'created', 'name', 'email'], string $schemaId = 'auth', array $inheritFrom = [], bool $authEnabled = true, array $definitions = [], string $ownerProperty = '', ?SchemaFetcher $schemaFetcher = null): AuthFieldPolicy
	{
		$schema              = new SchemaData();
		$schema->id          = $schemaId;
		$schema->inheritFrom = $inheritFrom;
		$schema->properties  = array_merge(array_fill_keys($props, []), $definitions);

		if ($schemaFetcher === null) {
			$schemaFetcher = $this->createMock(SchemaFetcher::class);
			$schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);
		}

		$userValidation = $this->createMock(UserValidationService::class);
		$userValidation->method('isSuperAdmin')->willReturn($isSuperAdmin);

		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$objectFetcher->method('existsObject')->willReturn($existing !== null);
		if ($existing !== null) {
			$object = $this->createMock(ObjectData::class);
			$object->method('toArray')->willReturn($existing);
			$objectFetcher->method('fetchObject')->willReturn($object);
		}

		$ownerProperties = $this->createMock(OwnerPropertyResolver::class);
		$ownerProperties->method('resolve')->willReturn($ownerProperty);

		$config       = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->auth = ['enable' => $authEnabled, 'collection' => 'auth'];

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')->willReturn($this->logger);

		return new AuthFieldPolicy($schemaFetcher, $userValidation, $objectFetcher, $ownerProperties, $config, $loggerFactory);
	}

	/** Collects log records so tests can assert on the audit trail. */
	private function makeLogger(): LoggerInterface
	{
		return new class extends AbstractLogger {
			/** @var list<array{level:mixed,message:string,context:array<string,mixed>}> */
			public array $records = [];

			/**
			 * @param array<string,mixed> $context
			 * @param mixed $level
			 */
			public function log($level, string|\Stringable $message, array $context = []): void
			{
				$this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
			}
		};
	}

	// -----------------------------------------------------------------------
	// auth.enable = false — there is no privilege model to enforce
	// -----------------------------------------------------------------------

	public function testEnforceIsNoOpWhenAuthIsDisabled(): void
	{
		// With auth off, both middlewares pass every request straight through and
		// no session user can ever resolve as a super-admin. Reverting privileged
		// fields would make them permanently unwritable — which is what silently
		// broke the Active toggle on a dev install running auth.enable = false.
		$policy = $this->makePolicy(
			isSuperAdmin: false,
			existing: ['active' => false, 'groups' => ['viewer']],
			authEnabled: false,
		);

		$out = $policy->enforce('', 'auth', 'viewer-user', ['active' => true, 'groups' => ['admin'], 'name' => 'V']);

		expect($out['active'])->toBe(true);
		expect($out['groups'])->toBe(['admin']);
	}

	public function testCanWritePropertyAllowsPrivilegedPropsWhenAuthIsDisabled(): void
	{
		$policy = $this->makePolicy(isSuperAdmin: false, authEnabled: false);

		expect($policy->canWriteProperty('', 'auth', 'active'))->toBeTrue();
		expect($policy->canWriteProperty('', 'auth', 'groups'))->toBeTrue();
	}

	// -----------------------------------------------------------------------
	// Audit trail. The admin form PUTs the whole object, so privileged fields
	// ride along on every save whether or not anyone touched them. Only a value
	// that actually differs from what is stored is an attempted write — that
	// distinction is what keeps the log signal rather than noise.
	// -----------------------------------------------------------------------

	public function testDoesNotLogWhenPrivilegedFieldsRideAlongUnchanged(): void
	{
		$policy = $this->makePolicy(
			isSuperAdmin: false,
			existing: ['active' => true, 'groups' => ['viewer'], 'name' => 'Old'],
		);

		$out = $policy->enforce('viewer1', 'auth', 'viewer1', ['active' => true, 'groups' => ['viewer'], 'name' => 'New']);

		expect($out['name'])->toBe('New');
		expect($this->logRecords())->toBe([]);
	}

	public function testLogsWhenAPrivilegedFieldIsActuallyChanged(): void
	{
		$policy = $this->makePolicy(
			isSuperAdmin: false,
			existing: ['active' => true, 'groups' => ['viewer'], 'name' => 'Old'],
		);

		$out = $policy->enforce('viewer1', 'auth', 'viewer1', ['active' => false, 'groups' => ['viewer'], 'name' => 'New']);

		expect($out['active'])->toBe(true); // still reverted
		expect($out['name'])->toBe('New');  // ordinary field still saved

		$records = $this->logRecords();
		expect($records)->toHaveCount(1);
		expect($records[0]['context']['reverted'])->toBe(['active']);
		expect($records[0]['context']['actor'])->toBe('viewer1');
		expect($records[0]['context']['collection'])->toBe('auth');
		expect($records[0]['context']['object'])->toBe('viewer1');
	}

	public function testLogsStrippedFieldsOnCreate(): void
	{
		$policy = $this->makePolicy(isSuperAdmin: false);

		$policy->enforce('viewer1', 'auth', 'newuser', ['groups' => ['admin'], 'name' => 'X']);

		$records = $this->logRecords();
		expect($records)->toHaveCount(1);
		expect($records[0]['context']['stripped'])->toBe(['groups']);
	}

	public function testDoesNotLogForSuperAdmin(): void
	{
		$policy = $this->makePolicy(isSuperAdmin: true, existing: ['active' => true]);

		$policy->enforce('admin', 'auth', 'u1', ['active' => false]);

		expect($this->logRecords())->toBe([]);
	}

	public function testDoesNotLogWhenAuthIsDisabled(): void
	{
		$policy = $this->makePolicy(isSuperAdmin: false, existing: ['active' => true], authEnabled: false);

		$policy->enforce('', 'auth', 'u1', ['active' => false]);

		expect($this->logRecords())->toBe([]);
	}

	public function testStripProtectedStillStripsWhenAuthIsDisabled(): void
	{
		// Deliberate exception. stripProtected() serves public registration, which
		// is reachable by anonymous callers and has no actor to trust. Letting it
		// through with auth off would bake `groups:['admin']` into records that
		// become live escalations the moment auth is switched back on.
		$policy = $this->makePolicy(isSuperAdmin: false, authEnabled: false);

		$out = $policy->stripProtected('auth', ['name' => 'x', 'groups' => ['admin'], 'active' => true]);

		expect($out)->toBe(['name' => 'x']);
	}

	public function testRevertsPrivilegedFieldsToStoredForNonAdmin(): void
	{
		$policy = $this->makePolicy(isSuperAdmin: false, existing: ['groups' => ['member'], 'active' => true, 'name' => 'Old']);

		$out = $policy->enforce('u1', 'auth', 'u1', ['groups' => ['admin'], 'active' => false, 'name' => 'New']);

		expect($out['groups'])->toBe(['member']); // reverted
		expect($out['active'])->toBe(true);        // reverted
		expect($out['name'])->toBe('New');         // user field kept
	}

	public function testStripsPrivilegedFieldsOnCreateForNonAdmin(): void
	{
		$policy = $this->makePolicy(isSuperAdmin: false);

		$out = $policy->enforce('u1', 'auth', 'newuser', ['groups' => ['admin'], 'name' => 'X']);

		expect($out)->not->toHaveKey('groups');
		expect($out['name'])->toBe('X');
	}

	public function testLeavesDataUntouchedForSuperAdmin(): void
	{
		$policy = $this->makePolicy(isSuperAdmin: true, existing: ['groups' => ['member']]);

		$out = $policy->enforce('admin', 'auth', 'u1', ['groups' => ['admin']]);

		expect($out['groups'])->toBe(['admin']);
	}

	public function testNoOpForCollectionWithoutPrivilegedFields(): void
	{
		$policy = $this->makePolicy(isSuperAdmin: false, existing: ['name' => 'x'], props: ['name', 'body']);

		$out = $policy->enforce('u1', 'auth', 'p1', ['name' => 'x']);

		expect($out)->toBe(['name' => 'x']);
	}

	public function testNoOpForNonAuthSchemaEvenWithCollidingFieldNames(): void
	{
		// A content collection (schema 'blog') that happens to have `active` and
		// `groups` fields must NOT have them treated as privileged.
		$policy = $this->makePolicy(
			isSuperAdmin: false,
			existing: ['active' => true, 'groups' => ['x']],
			props: ['active', 'groups', 'title'],
			schemaId: 'blog',
		);

		$out = $policy->enforce('u1', 'blog', 'p1', ['active' => false, 'groups' => ['y'], 'title' => 'New']);

		expect($out)->toBe(['active' => false, 'groups' => ['y'], 'title' => 'New']);
	}

	public function testAppliesToSchemaInheritingFromAuth(): void
	{
		// A custom 'members' schema that inherits from auth IS protected.
		$policy = $this->makePolicy(
			isSuperAdmin: false,
			existing: ['groups' => ['member']],
			props: ['groups', 'name'],
			schemaId: 'members',
			inheritFrom: ['auth'],
		);

		$out = $policy->enforce('u1', 'members', 'm1', ['groups' => ['admin'], 'name' => 'New']);

		expect($out['groups'])->toBe(['member']); // reverted via inheritance
		expect($out['name'])->toBe('New');
	}

	public function testStripProtectedRemovesOnlySchemaPresentPrivilegedFields(): void
	{
		$policy = $this->makePolicy(isSuperAdmin: false);

		$out = $policy->stripProtected('auth', ['name' => 'x', 'groups' => ['admin'], 'active' => true]);

		expect($out)->toBe(['name' => 'x']);
	}

	public function testCanWritePropertyGatesPrivilegedPropsForNonAdmins(): void
	{
		expect($this->makePolicy(isSuperAdmin: false)->canWriteProperty('u1', 'auth', 'groups'))->toBeFalse();
		expect($this->makePolicy(isSuperAdmin: false)->canWriteProperty('u1', 'auth', 'name'))->toBeTrue();
		expect($this->makePolicy(isSuperAdmin: true)->canWriteProperty('admin', 'auth', 'groups'))->toBeTrue();
	}

	/**
	 * `maxLoginCount` was protected but `loginCount` — the counter it is
	 * measured against — was not, so a user at their quota could save their own
	 * profile with `loginCount: 0` and hand themselves unlimited logins.
	 * See LoginService::164 / PasskeyLoginAction::94.
	 */
	public function testANonAdminCannotResetTheirOwnLoginQuota(): void
	{
		$policy = $this->makePolicy(
			isSuperAdmin: false,
			existing: ['loginCount' => 5, 'maxLoginCount' => 5, 'name' => 'Old'],
		);

		$out = $policy->enforce('u1', 'auth', 'u1', ['loginCount' => 0, 'name' => 'New']);

		expect($out['loginCount'])->toBe(5);
		expect($out['name'])->toBe('New');
	}

	public function testANonAdminCannotForgeTheirAuditTrail(): void
	{
		$policy = $this->makePolicy(
			isSuperAdmin: false,
			existing: ['lastlogin' => '2026-01-01T00:00:00+00:00', 'created' => '2020-06-06T00:00:00+00:00'],
		);

		$out = $policy->enforce('u1', 'auth', 'u1', [
			'lastlogin' => '2026-09-04T00:00:00+00:00',
			'created'   => '1999-01-01T00:00:00+00:00',
		]);

		expect($out['lastlogin'])->toBe('2026-01-01T00:00:00+00:00');
		expect($out['created'])->toBe('2020-06-06T00:00:00+00:00');
	}

	/**
	 * A super-admin still needs to correct these by hand — e.g. clearing a
	 * login quota for a user who hit it legitimately.
	 */
	public function testASuperAdminMayStillSetTheCounterAndTimestamps(): void
	{
		$policy = $this->makePolicy(
			isSuperAdmin: true,
			existing: ['loginCount' => 5, 'lastlogin' => '2026-01-01T00:00:00+00:00'],
		);

		$out = $policy->enforce('admin', 'auth', 'u1', ['loginCount' => 0, 'lastlogin' => 'now']);

		expect($out['loginCount'])->toBe(0);
		expect($out['lastlogin'])->toBe('now');
	}

	/** Public registration must not be able to seed a counter or a birth date. */
	public function testRegistrationStripsTheCounterAndTimestamps(): void
	{
		$out = $this->makePolicy(isSuperAdmin: false)->stripProtected('auth', [
			'name'       => 'x',
			'loginCount' => -100,
			'lastlogin'  => 'whenever',
			'created'    => '1999-01-01T00:00:00+00:00',
		]);

		expect($out)->toBe(['name' => 'x']);
	}

	// -----------------------------------------------------------------------
	// Schema-declared protection: `"protected": true` on any collection.
	// Same semantics as the privileged list — revert on update, strip on
	// create, log the neutralised attempt — but opt-in per property, so an
	// ordinary content collection can have an admin-only `featured` flag.
	// -----------------------------------------------------------------------

	public function testProtectedTrueRevertsOnUpdateForNonAdmin(): void
	{
		$policy = $this->makePolicy(
			isSuperAdmin: false,
			existing: ['featured' => false, 'title' => 'Old'],
			props: ['id', 'title'],
			schemaId: 'showcase',
			definitions: ['featured' => ['protected' => true]],
		);

		$out = $policy->enforce('member1', 'showcase', 'site-1', ['featured' => true, 'title' => 'New']);

		expect($out['featured'])->toBeFalse();
		expect($out['title'])->toBe('New');
		expect($this->logRecords()[0]['context']['reverted'])->toBe(['featured']);
	}

	public function testProtectedTrueStripsOnCreateForNonAdmin(): void
	{
		$policy = $this->makePolicy(
			isSuperAdmin: false,
			props: ['id', 'title'],
			schemaId: 'showcase',
			definitions: ['featured' => ['protected' => true]],
		);

		$out = $policy->enforce('member1', 'showcase', '', ['featured' => true, 'title' => 'New']);

		expect($out)->not->toHaveKey('featured');
		expect($this->logRecords()[0]['context']['stripped'])->toBe(['featured']);
	}

	public function testProtectedTrueDoesNotBindSuperAdmin(): void
	{
		$policy = $this->makePolicy(
			isSuperAdmin: true,
			existing: ['featured' => false],
			props: ['id', 'title'],
			schemaId: 'showcase',
			definitions: ['featured' => ['protected' => true]],
		);

		$out = $policy->enforce('admin', 'showcase', 'site-1', ['featured' => true]);

		expect($out['featured'])->toBeTrue();
		expect($this->logRecords())->toBe([]);
	}

	// -----------------------------------------------------------------------
	// Forced values: `"protected": {"value": X}`. Every non-admin write sets
	// the property to X whether or not the client sent it — this is the
	// moderation rule (draft goes back to true on any member edit). It is the
	// configured behaviour rather than a neutralised attempt, so nothing is
	// logged.
	// -----------------------------------------------------------------------

	public function testForcedValueOverridesWhatANonAdminSent(): void
	{
		$policy = $this->makePolicy(
			isSuperAdmin: false,
			existing: ['draft' => false, 'title' => 'Old'],
			props: ['id', 'title'],
			schemaId: 'showcase',
			definitions: ['draft' => ['protected' => ['value' => true]]],
		);

		$out = $policy->enforce('member1', 'showcase', 'site-1', ['draft' => false, 'title' => 'New']);

		expect($out['draft'])->toBeTrue();
		expect($out['title'])->toBe('New');
		expect($this->logRecords())->toBe([]);
	}

	public function testForcedValueIsAddedToAPatchThatOmitsIt(): void
	{
		// An admin approved the site (draft: false). The member patches only the
		// title. The patch must still carry draft: true, or the approval survives
		// an edit nobody re-reviewed.
		$policy = $this->makePolicy(
			isSuperAdmin: false,
			existing: ['draft' => false, 'title' => 'Approved'],
			props: ['id', 'title'],
			schemaId: 'showcase',
			definitions: ['draft' => ['protected' => ['value' => true]]],
		);

		$out = $policy->enforce('member1', 'showcase', 'site-1', ['title' => 'Edited']);

		expect($out)->toBe(['title' => 'Edited', 'draft' => true]);
	}

	public function testForcedValueIsSetOnCreate(): void
	{
		$policy = $this->makePolicy(
			isSuperAdmin: false,
			props: ['id', 'title'],
			schemaId: 'showcase',
			definitions: ['draft' => ['protected' => ['value' => true]]],
		);

		$out = $policy->enforce('member1', 'showcase', '', ['title' => 'New', 'draft' => false]);

		expect($out['draft'])->toBeTrue();
	}

	public function testForcedValueMayBeAnyJsonValue(): void
	{
		$policy = $this->makePolicy(
			isSuperAdmin: false,
			props: ['id', 'title'],
			schemaId: 'showcase',
			definitions: ['status' => ['protected' => ['value' => 'pending']]],
		);

		$out = $policy->enforce('member1', 'showcase', '', ['title' => 'New']);

		expect($out['status'])->toBe('pending');
	}

	public function testForcedValueDoesNotBindSuperAdmin(): void
	{
		$policy = $this->makePolicy(
			isSuperAdmin: true,
			existing: ['draft' => true],
			props: ['id', 'title'],
			schemaId: 'showcase',
			definitions: ['draft' => ['protected' => ['value' => true]]],
		);

		$out = $policy->enforce('admin', 'showcase', 'site-1', ['draft' => false]);

		expect($out['draft'])->toBeFalse();
	}

	public function testInvalidProtectedShapeIsIgnoredAtRuntime(): void
	{
		// "protected": "yes" is a lint error, not a runtime one. The property
		// behaves as unprotected rather than taking the site down.
		$policy = $this->makePolicy(
			isSuperAdmin: false,
			existing: ['featured' => false],
			props: ['id', 'title'],
			schemaId: 'showcase',
			definitions: ['featured' => ['protected' => 'yes'], 'rank' => ['protected' => ['default' => 1]]],
		);

		$out = $policy->enforce('member1', 'showcase', 'site-1', ['featured' => true, 'rank' => 5]);

		expect($out)->toBe(['featured' => true, 'rank' => 5]);
	}

	// -----------------------------------------------------------------------
	// Owner stamping. The collection's ownerProperty is protected by being
	// named: set to the actor on create, reverted on update, never writable
	// through the property routes.
	// -----------------------------------------------------------------------

	public function testOwnerIsStampedWithTheActorOnCreate(): void
	{
		$policy = $this->makePolicy(
			isSuperAdmin: false,
			props: ['id', 'title', 'owner'],
			schemaId: 'showcase',
			ownerProperty: 'owner',
		);

		$out = $policy->enforce('member1', 'showcase', '', ['title' => 'New', 'owner' => 'someone-else']);

		expect($out['owner'])->toBe('member1');
		expect($this->logRecords())->toBe([]);
	}

	public function testOwnerIsStampedWhenTheClientDidNotSendIt(): void
	{
		$policy = $this->makePolicy(
			isSuperAdmin: false,
			props: ['id', 'title', 'owner'],
			schemaId: 'showcase',
			ownerProperty: 'owner',
		);

		$out = $policy->enforce('member1', 'showcase', '', ['title' => 'New']);

		expect($out['owner'])->toBe('member1');
	}

	public function testRefusesToStampAnUnresolvedOwner(): void
	{
		// Safety net behind the access middleware: with the collection record
		// unreadable there is no owner property to stamp.
		$policy = $this->makePolicy(
			isSuperAdmin: false,
			props: ['id', 'title', 'owner'],
			schemaId: 'showcase',
			ownerProperty: OwnerPropertyResolver::UNRESOLVED,
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Collection "showcase" settings could not be read; refusing to write an owned object.');

		$policy->enforce('member1', 'showcase', '', ['title' => 'New']);
	}

	public function testAnonymousCreateStoresAnEmptyOwner(): void
	{
		$policy = $this->makePolicy(
			isSuperAdmin: false,
			props: ['id', 'title', 'owner'],
			schemaId: 'showcase',
			ownerProperty: 'owner',
		);

		$out = $policy->enforce('', 'showcase', '', ['title' => 'New', 'owner' => 'forged']);

		expect($out['owner'])->toBe('');
	}

	public function testOwnerIsRevertedOnUpdate(): void
	{
		$policy = $this->makePolicy(
			isSuperAdmin: false,
			existing: ['owner' => 'member1', 'title' => 'Old'],
			props: ['id', 'title', 'owner'],
			schemaId: 'showcase',
			ownerProperty: 'owner',
		);

		$out = $policy->enforce('member1', 'showcase', 'site-1', ['owner' => 'member2', 'title' => 'New']);

		expect($out['owner'])->toBe('member1');
		expect($this->logRecords()[0]['context']['reverted'])->toBe(['owner']);
	}

	public function testSuperAdminMayReassignTheOwner(): void
	{
		$policy = $this->makePolicy(
			isSuperAdmin: true,
			existing: ['owner' => 'member1'],
			props: ['id', 'title', 'owner'],
			schemaId: 'showcase',
			ownerProperty: 'owner',
		);

		$out = $policy->enforce('admin', 'showcase', 'site-1', ['owner' => 'member2']);

		expect($out['owner'])->toBe('member2');
	}

	public function testProtectedFieldsForListsEveryKindOfProtection(): void
	{
		$policy = $this->makePolicy(
			isSuperAdmin: false,
			props: ['id', 'title', 'owner'],
			schemaId: 'showcase',
			definitions: ['featured' => ['protected' => true], 'draft' => ['protected' => ['value' => true]]],
			ownerProperty: 'owner',
		);

		expect($policy->protectedFieldsFor('showcase'))->toEqualCanonicalizing(['featured', 'draft', 'owner']);
	}

	public function testCanWritePropertyDeniesForcedAndOwnerPropertiesForNonAdmins(): void
	{
		$policy = $this->makePolicy(
			isSuperAdmin: false,
			props: ['id', 'title', 'owner'],
			schemaId: 'showcase',
			definitions: ['featured' => ['protected' => true], 'draft' => ['protected' => ['value' => true]]],
			ownerProperty: 'owner',
		);

		expect($policy->canWriteProperty('member1', 'showcase', 'featured'))->toBeFalse();
		expect($policy->canWriteProperty('member1', 'showcase', 'draft'))->toBeFalse();
		expect($policy->canWriteProperty('member1', 'showcase', 'owner'))->toBeFalse();
		expect($policy->canWriteProperty('member1', 'showcase', 'title'))->toBeTrue();
	}

	public function testPrivilegedListStillAppliesOnlyToAuthSchemas(): void
	{
		// `groups` on a content schema stays writable unless the schema says
		// `protected` — the auth privileged list must not leak to other schemas
		// now that the policy reads every collection.
		$policy = $this->makePolicy(
			isSuperAdmin: false,
			existing: ['groups' => ['x']],
			props: ['id', 'groups'],
			schemaId: 'teams',
		);

		$out = $policy->enforce('member1', 'teams', 't-1', ['groups' => ['y']]);

		expect($out['groups'])->toBe(['y']);
	}

	public function testEvaluatesTheRulesForACollectionOncePerRequest(): void
	{
		$schema             = new SchemaData();
		$schema->id         = 'notes';
		$schema->properties = ['title' => [], 'draft' => ['protected' => true]];

		$fetcher = $this->createMock(SchemaFetcher::class);
		$fetcher->expects($this->exactly(2))->method('fetchSchemaForCollection')->with('c')->willReturn($schema);

		$policy = $this->makePolicy(isSuperAdmin: false, schemaFetcher: $fetcher);

		$policy->protectedFieldsFor('c');
		$policy->protectedFieldsFor('c');
		$this->assertFalse($policy->canWriteProperty('u1', 'c', 'draft'));

		$policy->forgetRules();
		$policy->protectedFieldsFor('c');
	}
}
