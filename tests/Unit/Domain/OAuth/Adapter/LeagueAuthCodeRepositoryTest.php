<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OAuth\Adapter;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\OAuth\Adapter\LeagueAuthCodeEntity;
use TotalCMS\Domain\OAuth\Adapter\LeagueAuthCodeRepository;
use TotalCMS\Domain\OAuth\Repository\OAuthStateStore;

final class LeagueAuthCodeRepositoryTest extends TestCase
{
	private string $dir;

	private LeagueAuthCodeRepository $adapter;

	protected function setUp(): void
	{
		$this->dir     = sys_get_temp_dir() . '/oauth-authcode-test-' . uniqid('', true);
		$this->adapter = new LeagueAuthCodeRepository(new OAuthStateStore($this->dir));
	}

	protected function tearDown(): void
	{
		recursiveDelete($this->dir);
	}

	private function code(string $id, string $expires = '+10 minutes'): LeagueAuthCodeEntity
	{
		$entity = new LeagueAuthCodeEntity();
		$entity->setIdentifier($id);
		$entity->setExpiryDateTime(new \DateTimeImmutable($expires));

		return $entity;
	}

	public function testPersistedCodeIsNotRevoked(): void
	{
		$this->adapter->persistNewAuthCode($this->code('code-1'));

		$this->assertFalse($this->adapter->isAuthCodeRevoked('code-1'));
	}

	public function testRevokedCodeIsRevoked(): void
	{
		$this->adapter->persistNewAuthCode($this->code('code-2'));
		$this->adapter->revokeAuthCode('code-2');

		$this->assertTrue($this->adapter->isAuthCodeRevoked('code-2'));
	}

	public function testUnknownCodeIsRevoked(): void
	{
		$this->assertTrue($this->adapter->isAuthCodeRevoked('unknown'));
	}

	public function testCodeLivesAsLongAsItsExpiry(): void
	{
		$this->adapter->persistNewAuthCode($this->code('code-3', '+30 minutes'));

		$files = glob($this->dir . '/authcodes/*.json') ?: [];
		$this->assertCount(1, $files);
		$entry = json_decode((string)file_get_contents($files[0]), true);
		$this->assertEqualsWithDelta(time() + 1800, $entry['expires'], 5);
	}

	public function testGetNewAuthCodeReturnsEntity(): void
	{
		$this->assertInstanceOf(LeagueAuthCodeEntity::class, $this->adapter->getNewAuthCode());
	}
}
