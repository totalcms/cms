<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OAuth\Repository;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\OAuth\Repository\OAuthRevocationList;
use TotalCMS\Domain\OAuth\Repository\OAuthStateStore;

final class OAuthRevocationListTest extends TestCase
{
	private const TTL = 3600;

	private string $dir;

	protected function setUp(): void
	{
		$this->dir = sys_get_temp_dir() . '/oauth-revocation-test-' . uniqid('', true);
	}

	protected function tearDown(): void
	{
		recursiveDelete($this->dir);
	}

	public function testRevokedJtiIsRevoked(): void
	{
		$list = new OAuthRevocationList(new OAuthStateStore($this->dir), self::TTL);
		$list->revoke('test-jti-123');

		$this->assertTrue($list->isRevoked('test-jti-123'));
	}

	public function testUnrevokedJtiIsNotRevoked(): void
	{
		$list = new OAuthRevocationList(new OAuthStateStore($this->dir), self::TTL);

		$this->assertFalse($list->isRevoked('unknown-jti'));
	}

	public function testRevocationOutlivesTheInstanceThatWroteIt(): void
	{
		// A cache clear used to un-revoke a token; the list now lives on disk.
		(new OAuthRevocationList(new OAuthStateStore($this->dir), self::TTL))->revoke('jti-kept');

		$this->assertTrue((new OAuthRevocationList(new OAuthStateStore($this->dir), self::TTL))->isRevoked('jti-kept'));
	}
}
