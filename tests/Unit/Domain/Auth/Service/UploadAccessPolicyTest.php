<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Auth\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Auth\Service\UploadAccessPolicy;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Session\SessionKeys;

// Whether a raw upload (a file or video in a collection with access groups)
// may be served to the current session. The same rule sat inlined in
// DownloadUploadAction and StreamUploadAction, differing only in which
// property setting switches it on.
final class UploadAccessPolicyTest extends TestCase
{
	private function policy(array $groups, array $settings, array $session, bool $superAdmin = false, bool $hasAccess = true): UploadAccessPolicy
	{
		$collection = new CollectionData();
		$collection->id     = 'members';
		$collection->groups = $groups;
		$collections = $this->createMock(CollectionFetcher::class);
		$collections->method('fetchCollection')->willReturn($collection);

		$schema             = new SchemaData();
		$schema->properties = ['doc' => ['field' => 'file', 'settings' => $settings]];
		$schemas = $this->createMock(SchemaFetcher::class);
		$schemas->method('fetchSchemaForCollection')->willReturn($schema);

		$validator = $this->createMock(UserValidationService::class);
		$validator->method('isSuperAdmin')->willReturn($superAdmin);
		$validator->method('validateFileAccess')->willReturn($hasAccess);

		return new UploadAccessPolicy($collections, $schemas, new InMemorySession($session), $validator);
	}

	public function testACollectionWithoutGroupsIsOpen(): void
	{
		$this->assertNull($this->policy([], ['fileProtectedByCollection' => true], [])->denialFor('members', 'doc', 'fileProtectedByCollection'));
	}

	public function testThePropertyMustOptIn(): void
	{
		$this->assertNull($this->policy(['staff'], [], [])->denialFor('members', 'doc', 'fileProtectedByCollection'));
		$this->assertNull($this->policy(['staff'], ['videoProtectedByCollection' => true], [])->denialFor('members', 'doc', 'fileProtectedByCollection'));
	}

	public function testAnAnonymousVisitorMustAuthenticate(): void
	{
		$this->assertSame('Authentication required', $this->policy(['staff'], ['fileProtectedByCollection' => true], [])->denialFor('members', 'doc', 'fileProtectedByCollection'));
	}

	public function testASuperAdminIsAlwaysAllowed(): void
	{
		$session = [SessionKeys::AUTH_USER => 'root', SessionKeys::AUTH_COLLECTION => 'auth'];
		$this->assertNull($this->policy(['staff'], ['fileProtectedByCollection' => true], $session, superAdmin: true, hasAccess: false)->denialFor('members', 'doc', 'fileProtectedByCollection'));
	}

	public function testAUserOutsideTheCollectionGroupsIsDenied(): void
	{
		$session = [SessionKeys::AUTH_USER => 'bob', SessionKeys::AUTH_COLLECTION => 'members'];
		$this->assertSame('Access denied', $this->policy(['staff'], ['videoProtectedByCollection' => true], $session, hasAccess: false)->denialFor('members', 'doc', 'videoProtectedByCollection'));
		$this->assertNull($this->policy(['staff'], ['videoProtectedByCollection' => true], $session, hasAccess: true)->denialFor('members', 'doc', 'videoProtectedByCollection'));
	}
}
