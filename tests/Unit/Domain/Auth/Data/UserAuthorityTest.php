<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Auth\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\Auth\Data\UserAuthority;

// UserAuthority is the one permission engine. AccessControlService used to
// carry a second, session-flavoured copy of every rule (fourteen near-identical
// methods) and the two had drifted: the super-admin-only utils list and the
// per-extension grant existed only on the session side. Both now live here,
// so the OAuth path enforces them too.
final class UserAuthorityTest extends TestCase
{
	/** @param array<string,mixed> $permissions */
	private function group(array $permissions): AccessGroupData
	{
		return new AccessGroupData(['id' => 'g', 'name' => 'G', 'permissions' => $permissions]);
	}

	public function testSuperAdminOnlyUtilsAreNeverGrantedByAGroup(): void
	{
		$authority = new UserAuthority(isAdmin: false, groups: [$this->group(['utils' => ['all' => true]])]);

		$this->assertTrue($authority->canUtil('reports'));
		foreach (UserAuthority::SUPER_ADMIN_ONLY_UTILS as $util) {
			$this->assertFalse($authority->canUtil($util), $util);
		}

		$admin = new UserAuthority(isAdmin: true, groups: []);
		$this->assertTrue($admin->canUtil('jumpstart'));
	}

	public function testBooleanPermissionsMailerAndDocs(): void
	{
		$granted = new UserAuthority(isAdmin: false, groups: [$this->group(['mailer' => true, 'docs' => true])]);
		$denied  = new UserAuthority(isAdmin: false, groups: [$this->group(['mailer' => false])]);

		$this->assertTrue($granted->canMailer());
		$this->assertTrue($granted->canDocs());
		$this->assertFalse($denied->canMailer());
		$this->assertFalse($denied->canDocs());
		$this->assertTrue((new UserAuthority(isAdmin: true, groups: []))->canMailer());
	}

	public function testUtilsOperationFollowsTheBulkOperationRule(): void
	{
		$authority = new UserAuthority(isAdmin: false, groups: [$this->group(['utils' => ['all' => true, 'operations' => ['read']]])]);

		$this->assertTrue($authority->canUtilsOperation('read'));
		$this->assertFalse($authority->canUtilsOperation('update'));
		$this->assertFalse((new UserAuthority(isAdmin: false, groups: [$this->group(['utils' => ['operations' => ['read']]])]))->canUtilsOperation('read'), 'no allowed set and not all → nothing');
	}

	public function testExtensionsDefaultToAllAllowedForGroupsThatPredateTheKey(): void
	{
		$legacy   = new UserAuthority(isAdmin: false, groups: [$this->group(['docs' => true])]);
		$limited  = new UserAuthority(isAdmin: false, groups: [$this->group(['extensions' => ['all' => false, 'allowed' => ['acme/widget']]])]);
		$none     = new UserAuthority(isAdmin: false, groups: [$this->group(['extensions' => ['all' => false, 'allowed' => []]])]);

		$this->assertTrue($legacy->canExtension('anything/at-all'));
		$this->assertTrue($limited->canExtension('acme/widget'));
		$this->assertFalse($limited->canExtension('acme/other'));
		$this->assertFalse($none->canExtension('acme/widget'));
	}
}
