<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Auth\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Auth\Service\AuthFieldPolicy;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

final class AuthFieldPolicyTest extends TestCase
{
	private \Psr\Log\LoggerInterface $logger;

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
	 * @param list<string>             $props    schema property names
	 * @param array<string,mixed>|null $existing stored record, or null when it doesn't exist
	 */
	/**
	 * @param list<string> $props
	 * @param list<string> $inheritFrom
	 */
	private function makePolicy(bool $isSuperAdmin, ?array $existing = null, array $props = ['groups', 'active', 'expiration', 'maxLoginCount', 'passkeys', 'name', 'email'], string $schemaId = 'auth', array $inheritFrom = [], bool $authEnabled = true): AuthFieldPolicy
	{
		$schema              = new SchemaData();
		$schema->id          = $schemaId;
		$schema->inheritFrom = $inheritFrom;
		$schema->properties  = array_fill_keys($props, []);

		$schemaFetcher = $this->createMock(SchemaFetcher::class);
		$schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$userValidation = $this->createMock(UserValidationService::class);
		$userValidation->method('isSuperAdmin')->willReturn($isSuperAdmin);

		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$objectFetcher->method('existsObject')->willReturn($existing !== null);
		if ($existing !== null) {
			$object = $this->createMock(ObjectData::class);
			$object->method('toArray')->willReturn($existing);
			$objectFetcher->method('fetchObject')->willReturn($object);
		}

		$config       = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->auth = ['enable' => $authEnabled, 'collection' => 'auth'];

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')->willReturn($this->logger);

		return new AuthFieldPolicy($schemaFetcher, $userValidation, $objectFetcher, $config, $loggerFactory);
	}

	/** Collects log records so tests can assert on the audit trail. */
	private function makeLogger(): \Psr\Log\LoggerInterface
	{
		return new class extends \Psr\Log\AbstractLogger {
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
}
