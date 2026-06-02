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

final class AuthFieldPolicyTest extends TestCase
{
	/**
	 * @param list<string>             $props    schema property names
	 * @param array<string,mixed>|null $existing stored record, or null when it doesn't exist
	 */
	/**
	 * @param list<string> $props
	 * @param list<string> $inheritFrom
	 */
	private function makePolicy(bool $isSuperAdmin, ?array $existing = null, array $props = ['groups', 'active', 'expiration', 'maxLoginCount', 'passkeys', 'name', 'email'], string $schemaId = 'auth', array $inheritFrom = []): AuthFieldPolicy
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

		return new AuthFieldPolicy($schemaFetcher, $userValidation, $objectFetcher);
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
		$policy = $this->makePolicy(isSuperAdmin: false, existing: null);

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
		$policy = $this->makePolicy(isSuperAdmin: false, existing: null);

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
