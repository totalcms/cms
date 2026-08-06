<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Auth\Service;

use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Index\Service\IndexSearcher;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Support\Config;

final class UserValidationServiceTest extends TestCase
{
	public function testUserValidationServiceCanBeInstantiated(): void
	{
		$searcher      = $this->createMock(IndexSearcher::class);
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$config        = $this->createMock(Config::class);

		$service = new UserValidationService($searcher, $objectFetcher, $config);

		expect($service)->toBeInstanceOf(UserValidationService::class);
	}

	public function testValidateUserByEmailSuccessfully(): void
	{
		$searcher      = $this->createMock(IndexSearcher::class);
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$config        = $this->createMock(Config::class);

		// Mock config auth collection
		$config->auth = ['collection' => 'users'];

		// Mock search results
		$searchResults = new Collection([
			['id' => 'john-doe', 'email' => 'john@example.com'],
		]);

		$searcher->expects($this->once())
			->method('searchByProperty')
			->with('users', 'email', 'john@example.com')
			->willReturn($searchResults);

		// Mock object fetcher
		$mockUser = $this->createMock(ObjectData::class);
		$mockUser->method('toArray')->willReturn([
			'id'     => 'john-doe',
			'email'  => 'john@example.com',
			'groups' => ['editor'],
		]);

		$objectFetcher->expects($this->once())
			->method('existsObject')
			->with('users', 'john-doe')
			->willReturn(true);

		$objectFetcher->expects($this->once())
			->method('fetchObject')
			->with('users', 'john-doe')
			->willReturn($mockUser);

		$service = new UserValidationService($searcher, $objectFetcher, $config);

		$result = $service->validateUser('john@example.com');

		expect($result)->toBe([
			'id'     => 'john-doe',
			'email'  => 'john@example.com',
			'groups' => ['editor'],
		]);
	}

	public function testValidateUserByEmailThrowsExceptionWhenNotFound(): void
	{
		$searcher      = $this->createMock(IndexSearcher::class);
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$config        = $this->createMock(Config::class);

		$config->auth = ['collection' => 'users'];

		// Mock empty search results
		$searchResults = new Collection([]);

		$searcher->expects($this->once())
			->method('searchByProperty')
			->with('users', 'email', 'notfound@example.com')
			->willReturn($searchResults);

		$service = new UserValidationService($searcher, $objectFetcher, $config);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('User not found');

		$service->validateUser('notfound@example.com');
	}

	public function testValidateUserByIdSuccessfully(): void
	{
		$searcher      = $this->createMock(IndexSearcher::class);
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$config        = $this->createMock(Config::class);

		$config->auth = ['collection' => 'users'];

		$mockUser = $this->createMock(ObjectData::class);
		$mockUser->method('toArray')->willReturn([
			'id'     => 'jane-doe',
			'email'  => 'jane@example.com',
			'groups' => ['admin'],
		]);

		$objectFetcher->expects($this->once())
			->method('existsObject')
			->with('users', 'jane-doe')
			->willReturn(true);

		$objectFetcher->expects($this->once())
			->method('fetchObject')
			->with('users', 'jane-doe')
			->willReturn($mockUser);

		$service = new UserValidationService($searcher, $objectFetcher, $config);

		$result = $service->validateUserById('jane-doe');

		expect($result)->toBe([
			'id'     => 'jane-doe',
			'email'  => 'jane@example.com',
			'groups' => ['admin'],
		]);
	}

	public function testValidateUserByIdThrowsExceptionForEmptyUserId(): void
	{
		$searcher      = $this->createMock(IndexSearcher::class);
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$config        = $this->createMock(Config::class);

		$config->auth = ['collection' => 'users'];

		$service = new UserValidationService($searcher, $objectFetcher, $config);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('No User ID provided');

		$service->validateUserById('');
	}

	public function testValidateUserByIdThrowsExceptionWhenUserDoesNotExist(): void
	{
		$searcher      = $this->createMock(IndexSearcher::class);
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$config        = $this->createMock(Config::class);

		$config->auth = ['collection' => 'users'];

		$objectFetcher->expects($this->once())
			->method('existsObject')
			->with('users', 'nonexistent')
			->willReturn(false);

		$service = new UserValidationService($searcher, $objectFetcher, $config);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('User nonexistent does not exist');

		$service->validateUserById('nonexistent');
	}

	public function testValidateUserByIdAllowsSuperAdminFromDifferentCollection(): void
	{
		$searcher      = $this->createMock(IndexSearcher::class);
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$config        = $this->createMock(Config::class);

		$config->auth = ['collection' => 'users'];

		// Mock object existence checks - user doesn't exist in customers, but exists in users
		$objectFetcher->method('existsObject')
			->willReturnCallback(function ($collection, $userId): bool {
				if ($collection === 'customers' && $userId === 'super-admin') {
					return false; // User doesn't exist in customers collection
				}
				if ($collection === 'users' && $userId === 'super-admin') {
					return true; // User exists in default auth collection
				}

				return false;
			});

		// Mock super admin user in default collection
		$mockUser = $this->createMock(ObjectData::class);
		$mockUser->method('toArray')->willReturn([
			'id'     => 'super-admin',
			'email'  => 'admin@example.com',
			'groups' => ['admin'],
		]);

		$objectFetcher->method('fetchObject')
			->with('users', 'super-admin')
			->willReturn($mockUser);

		$service = new UserValidationService($searcher, $objectFetcher, $config);

		$result = $service->validateUserById('super-admin', 'customers');

		expect($result)->toBe([
			'id'     => 'super-admin',
			'email'  => 'admin@example.com',
			'groups' => ['admin'],
		]);
	}

	public function testValidateUserInGroupsWithStringGroup(): void
	{
		$searcher      = $this->createMock(IndexSearcher::class);
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$config        = $this->createMock(Config::class);

		$config->auth = ['collection' => 'users'];

		$mockUser = $this->createMock(ObjectData::class);
		$mockUser->method('toArray')->willReturn([
			'id'     => 'editor-user',
			'groups' => ['editor', 'writer'],
		]);

		$objectFetcher->expects($this->once())
			->method('existsObject')
			->with('users', 'editor-user')
			->willReturn(true);

		$objectFetcher->expects($this->once())
			->method('fetchObject')
			->with('users', 'editor-user')
			->willReturn($mockUser);

		$service = new UserValidationService($searcher, $objectFetcher, $config);

		$result = $service->validateUserInGroups('editor-user', 'editor');

		expect($result)->toBeTrue();
	}

	public function testValidateUserInGroupsWithArrayGroups(): void
	{
		$searcher      = $this->createMock(IndexSearcher::class);
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$config        = $this->createMock(Config::class);

		$config->auth = ['collection' => 'users'];

		$mockUser = $this->createMock(ObjectData::class);
		$mockUser->method('toArray')->willReturn([
			'id'     => 'multi-user',
			'groups' => ['editor', 'writer', 'reviewer'],
		]);

		$objectFetcher->expects($this->once())
			->method('existsObject')
			->with('users', 'multi-user')
			->willReturn(true);

		$objectFetcher->expects($this->once())
			->method('fetchObject')
			->with('users', 'multi-user')
			->willReturn($mockUser);

		$service = new UserValidationService($searcher, $objectFetcher, $config);

		$result = $service->validateUserInGroups('multi-user', ['designer', 'writer']);

		expect($result)->toBeTrue();
	}

	public function testValidateUserInGroupsReturnsFalseWhenUserNotFound(): void
	{
		$searcher      = $this->createMock(IndexSearcher::class);
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$config        = $this->createMock(Config::class);

		$config->auth = ['collection' => 'users'];

		$objectFetcher->expects($this->once())
			->method('existsObject')
			->with('users', 'missing-user')
			->willReturn(false);

		$service = new UserValidationService($searcher, $objectFetcher, $config);

		$result = $service->validateUserInGroups('missing-user', 'editor');

		expect($result)->toBeFalse();
	}

	public function testValidateUserInGroupsAllowsAdminUsers(): void
	{
		$searcher      = $this->createMock(IndexSearcher::class);
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$config        = $this->createMock(Config::class);

		$config->auth = ['collection' => 'users'];

		$mockUser = $this->createMock(ObjectData::class);
		$mockUser->method('toArray')->willReturn([
			'id'     => 'admin-user',
			'groups' => ['admin'],
		]);

		$objectFetcher->expects($this->once())
			->method('existsObject')
			->with('users', 'admin-user')
			->willReturn(true);

		$objectFetcher->expects($this->once())
			->method('fetchObject')
			->with('users', 'admin-user')
			->willReturn($mockUser);

		$service = new UserValidationService($searcher, $objectFetcher, $config);

		// Admin user should have access to 'restricted' group even though not in their groups
		$result = $service->validateUserInGroups('admin-user', 'restricted');

		expect($result)->toBeTrue();
	}

	public function testIsSuperAdminReturnsTrueForAdminUser(): void
	{
		$searcher      = $this->createMock(IndexSearcher::class);
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$config        = $this->createMock(Config::class);

		$config->auth = ['collection' => 'users'];

		$mockUser = $this->createMock(ObjectData::class);
		$mockUser->method('toArray')->willReturn([
			'id'     => 'super-admin',
			'groups' => ['admin', 'editor'],
		]);

		$objectFetcher->expects($this->once())
			->method('existsObject')
			->with('users', 'super-admin')
			->willReturn(true);

		$objectFetcher->expects($this->once())
			->method('fetchObject')
			->with('users', 'super-admin')
			->willReturn($mockUser);

		$service = new UserValidationService($searcher, $objectFetcher, $config);

		$result = $service->isSuperAdmin('super-admin');

		expect($result)->toBeTrue();
	}

	public function testIsSuperAdminReturnsFalseForNonAdminUser(): void
	{
		$searcher      = $this->createMock(IndexSearcher::class);
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$config        = $this->createMock(Config::class);

		$config->auth = ['collection' => 'users'];

		$mockUser = $this->createMock(ObjectData::class);
		$mockUser->method('toArray')->willReturn([
			'id'     => 'regular-user',
			'groups' => ['editor', 'writer'],
		]);

		$objectFetcher->expects($this->once())
			->method('existsObject')
			->with('users', 'regular-user')
			->willReturn(true);

		$objectFetcher->expects($this->once())
			->method('fetchObject')
			->with('users', 'regular-user')
			->willReturn($mockUser);

		$service = new UserValidationService($searcher, $objectFetcher, $config);

		$result = $service->isSuperAdmin('regular-user');

		expect($result)->toBeFalse();
	}

	public function testIsSuperAdminReturnsFalseWhenUserDoesNotExist(): void
	{
		$searcher      = $this->createMock(IndexSearcher::class);
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$config        = $this->createMock(Config::class);

		$config->auth = ['collection' => 'users'];

		$objectFetcher->expects($this->once())
			->method('existsObject')
			->with('users', 'nonexistent')
			->willReturn(false);

		$service = new UserValidationService($searcher, $objectFetcher, $config);

		$result = $service->isSuperAdmin('nonexistent');

		expect($result)->toBeFalse();
	}

	public function testUsesDefaultCollectionWhenNotSpecified(): void
	{
		$searcher      = $this->createMock(IndexSearcher::class);
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$config        = $this->createMock(Config::class);

		$config->auth = ['collection' => 'default-users'];

		$mockUser = $this->createMock(ObjectData::class);
		$mockUser->method('toArray')->willReturn([
			'id'     => 'test-user',
			'groups' => ['member'],
		]);

		$objectFetcher->expects($this->once())
			->method('existsObject')
			->with('default-users', 'test-user')  // Should use default collection
			->willReturn(true);

		$objectFetcher->expects($this->once())
			->method('fetchObject')
			->with('default-users', 'test-user')
			->willReturn($mockUser);

		$service = new UserValidationService($searcher, $objectFetcher, $config);

		$result = $service->validateUserById('test-user'); // No collection specified

		expect($result['id'])->toBe('test-user');
	}

	// -----------------------------------------------------------------------
	// Cross-collection isSuperAdmin() hardening: super admins can only exist
	// in the default auth collection. A caller authenticated against any
	// OTHER auth collection must never be treated as a super admin, even when
	// its object id collides with an admin's id in the default collection.
	// -----------------------------------------------------------------------

	public function testIsSuperAdminIsFalseWhenIdCollidesWithDefaultCollectionAdminButCallerIsInAnotherCollection(): void
	{
		$searcher      = $this->createMock(IndexSearcher::class);
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$config        = $this->createMock(Config::class);

		$config->auth = ['collection' => 'auth'];

		// The default collection lookup would say yes (same id IS an admin
		// there) — but existsObject/fetchObject must never even be consulted
		// once the collection mismatch is detected.
		$objectFetcher->expects($this->never())->method('existsObject');
		$objectFetcher->expects($this->never())->method('fetchObject');

		$service = new UserValidationService($searcher, $objectFetcher, $config);

		// Same id, but the CALLER belongs to 'members', not the default 'auth'.
		$result = $service->isSuperAdmin('shared-id', 'members');

		expect($result)->toBeFalse();
	}

	public function testIsSuperAdminIsTrueWhenCallerCollectionMatchesDefault(): void
	{
		$searcher      = $this->createMock(IndexSearcher::class);
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$config        = $this->createMock(Config::class);

		$config->auth = ['collection' => 'auth'];

		$mockUser = $this->createMock(ObjectData::class);
		$mockUser->method('toArray')->willReturn([
			'id'     => 'shared-id',
			'groups' => ['admin'],
		]);

		$objectFetcher->method('existsObject')->with('auth', 'shared-id')->willReturn(true);
		$objectFetcher->method('fetchObject')->with('auth', 'shared-id')->willReturn($mockUser);

		$service = new UserValidationService($searcher, $objectFetcher, $config);

		expect($service->isSuperAdmin('shared-id', 'auth'))->toBeTrue();
	}

	public function testIsSuperAdminIsFalseForNonAdminInDefaultCollectionWithExplicitCollection(): void
	{
		$searcher      = $this->createMock(IndexSearcher::class);
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$config        = $this->createMock(Config::class);

		$config->auth = ['collection' => 'auth'];

		$mockUser = $this->createMock(ObjectData::class);
		$mockUser->method('toArray')->willReturn([
			'id'     => 'regular-user',
			'groups' => ['editor'],
		]);

		$objectFetcher->method('existsObject')->with('auth', 'regular-user')->willReturn(true);
		$objectFetcher->method('fetchObject')->with('auth', 'regular-user')->willReturn($mockUser);

		$service = new UserValidationService($searcher, $objectFetcher, $config);

		expect($service->isSuperAdmin('regular-user', 'auth'))->toBeFalse();
	}

	public function testIsSuperAdminWithEmptyCollectionAssumesDefaultCollection(): void
	{
		$searcher      = $this->createMock(IndexSearcher::class);
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$config        = $this->createMock(Config::class);

		$config->auth = ['collection' => 'auth'];

		$mockUser = $this->createMock(ObjectData::class);
		$mockUser->method('toArray')->willReturn([
			'id'     => 'shared-id',
			'groups' => ['admin'],
		]);

		$objectFetcher->method('existsObject')->with('auth', 'shared-id')->willReturn(true);
		$objectFetcher->method('fetchObject')->with('auth', 'shared-id')->willReturn($mockUser);

		$service = new UserValidationService($searcher, $objectFetcher, $config);

		// '' is the historical single-argument meaning: caller didn't specify
		// a collection, so assume the default one — same result as before
		// this parameter existed.
		expect($service->isSuperAdmin('shared-id'))->toBeTrue();
		expect($service->isSuperAdmin('shared-id', ''))->toBeTrue();
	}

	public function testValidateFileAccessWithDefaultGroupAllowsUserInAnotherGroup(): void
	{
		$searcher      = $this->createMock(IndexSearcher::class);
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$config        = $this->createMock(Config::class);

		$config->auth = ['collection' => 'users'];

		// User belongs to 'editor' only — NOT 'default'.
		$mockUser = $this->createMock(ObjectData::class);
		$mockUser->method('toArray')->willReturn([
			'id'     => 'editor-user',
			'groups' => ['editor'],
		]);

		$objectFetcher->method('existsObject')->with('users', 'editor-user')->willReturn(true);
		$objectFetcher->method('fetchObject')->with('users', 'editor-user')->willReturn($mockUser);

		$service = new UserValidationService($searcher, $objectFetcher, $config);

		// 'default' in File Access Groups means any authenticated user — the
		// editor gets in even though 'editor' is not in the required list.
		expect($service->validateFileAccess('editor-user', ['default']))->toBeTrue();
	}

	public function testValidateFileAccessWithDefaultGroupAllowsGrouplessUser(): void
	{
		$searcher      = $this->createMock(IndexSearcher::class);
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$config        = $this->createMock(Config::class);

		$config->auth = ['collection' => 'users'];

		$mockUser = $this->createMock(ObjectData::class);
		$mockUser->method('toArray')->willReturn([
			'id'     => 'groupless-user',
			'groups' => [],
		]);

		$objectFetcher->method('existsObject')->with('users', 'groupless-user')->willReturn(true);
		$objectFetcher->method('fetchObject')->with('users', 'groupless-user')->willReturn($mockUser);

		$service = new UserValidationService($searcher, $objectFetcher, $config);

		expect($service->validateFileAccess('groupless-user', ['default']))->toBeTrue();
	}

	public function testValidateFileAccessWithDefaultGroupReturnsFalseWhenUserDoesNotExist(): void
	{
		$searcher      = $this->createMock(IndexSearcher::class);
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$config        = $this->createMock(Config::class);

		$config->auth = ['collection' => 'users'];

		// A session may reference a deleted user — "default" must still verify existence.
		$objectFetcher->method('existsObject')->with('users', 'ghost')->willReturn(false);

		$service = new UserValidationService($searcher, $objectFetcher, $config);

		expect($service->validateFileAccess('ghost', ['default']))->toBeFalse();
	}

	public function testValidateFileAccessWithoutDefaultGroupExcludesNonMembers(): void
	{
		$searcher      = $this->createMock(IndexSearcher::class);
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$config        = $this->createMock(Config::class);

		$config->auth = ['collection' => 'users'];

		$mockUser = $this->createMock(ObjectData::class);
		$mockUser->method('toArray')->willReturn([
			'id'     => 'editor-user',
			'groups' => ['editor'],
		]);

		$objectFetcher->method('existsObject')->with('users', 'editor-user')->willReturn(true);
		$objectFetcher->method('fetchObject')->with('users', 'editor-user')->willReturn($mockUser);

		$service = new UserValidationService($searcher, $objectFetcher, $config);

		// No 'default' — falls through to group membership, editor is not a member.
		expect($service->validateFileAccess('editor-user', ['members']))->toBeFalse();
	}

	public function testValidateFileAccessWithoutDefaultGroupAllowsMembers(): void
	{
		$searcher      = $this->createMock(IndexSearcher::class);
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$config        = $this->createMock(Config::class);

		$config->auth = ['collection' => 'users'];

		$mockUser = $this->createMock(ObjectData::class);
		$mockUser->method('toArray')->willReturn([
			'id'     => 'member-user',
			'groups' => ['members'],
		]);

		$objectFetcher->method('existsObject')->with('users', 'member-user')->willReturn(true);
		$objectFetcher->method('fetchObject')->with('users', 'member-user')->willReturn($mockUser);

		$service = new UserValidationService($searcher, $objectFetcher, $config);

		expect($service->validateFileAccess('member-user', ['members']))->toBeTrue();
	}

	public function testConstants(): void
	{
		expect(UserValidationService::ADMINGROUP)->toBe('admin');
		expect(UserValidationService::DEFAULTGROUP)->toBe('default');
	}

	public function testFindUserByEmailReturnsObjectDataWhenFound(): void
	{
		$searcher      = $this->createMock(IndexSearcher::class);
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$config        = $this->createMock(Config::class);

		$config->auth = ['collection' => 'users'];

		$searchResults = new Collection([
			['id' => 'user-1', 'email' => 'a@b.test'],
		]);

		$searcher->expects($this->once())
			->method('searchByProperty')
			->with('users', 'email', 'a@b.test')
			->willReturn($searchResults);

		$mockUser = $this->createMock(ObjectData::class);

		$objectFetcher->expects($this->once())
			->method('fetchObject')
			->with('users', 'user-1')
			->willReturn($mockUser);

		$service = new UserValidationService($searcher, $objectFetcher, $config);

		$result = $service->findUserByEmail('a@b.test');

		expect($result)->toBe($mockUser);
	}

	public function testFindUserByEmailReturnsNullWhenUserDoesNotExist(): void
	{
		$searcher      = $this->createMock(IndexSearcher::class);
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$config        = $this->createMock(Config::class);

		$config->auth = ['collection' => 'users'];

		// Empty results — unlike validateUserByEmail, this MUST NOT throw.
		$searcher->expects($this->once())
			->method('searchByProperty')
			->willReturn(new Collection([]));

		// And we should never try to fetch the object.
		$objectFetcher->expects($this->never())->method('fetchObject');

		$service = new UserValidationService($searcher, $objectFetcher, $config);

		expect($service->findUserByEmail('ghost@example.com'))->toBeNull();
	}

	public function testFindUserByEmailReturnsNullWhenSearcherThrows(): void
	{
		// Defensive: a broken index lookup must not bubble up as an exception
		// in the anti-enumeration flows. Same posture as validateUser's miss
		// — callers can't distinguish "user not there" from "lookup failed".
		$searcher      = $this->createMock(IndexSearcher::class);
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$config        = $this->createMock(Config::class);

		$config->auth = ['collection' => 'users'];

		$searcher->method('searchByProperty')
			->willThrowException(new \RuntimeException('index corrupt'));

		$service = new UserValidationService($searcher, $objectFetcher, $config);

		expect($service->findUserByEmail('a@b.test'))->toBeNull();
	}

	public function testFindUserByEmailUsesGivenCollectionWhenSpecified(): void
	{
		$searcher      = $this->createMock(IndexSearcher::class);
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$config        = $this->createMock(Config::class);

		// Default collection is 'admin' — but caller passes 'members'.
		$config->auth = ['collection' => 'admin'];

		$searcher->expects($this->once())
			->method('searchByProperty')
			->with('members', 'email', 'a@b.test')  // ← uses 'members', not the default
			->willReturn(new Collection([['id' => 'user-1']]));

		$objectFetcher->method('fetchObject')->willReturn($this->createMock(ObjectData::class));

		$service = new UserValidationService($searcher, $objectFetcher, $config);

		$service->findUserByEmail('a@b.test', 'members');
	}
}
