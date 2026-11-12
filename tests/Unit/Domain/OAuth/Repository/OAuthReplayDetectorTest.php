<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OAuth\Repository;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\OAuth\Repository\OAuthReplayDetector;
use TotalCMS\Domain\OAuth\Repository\OAuthStateStore;

final class OAuthReplayDetectorTest extends TestCase
{
	private const TTL = 2592000; // 30 days in seconds

	private string $dir;

	private OAuthReplayDetector $detector;

	protected function setUp(): void
	{
		$this->dir      = sys_get_temp_dir() . '/oauth-replay-test-' . uniqid('', true);
		$this->detector = new OAuthReplayDetector(new OAuthStateStore($this->dir), self::TTL);
	}

	protected function tearDown(): void
	{
		recursiveDelete($this->dir);
	}

	public function testRecordRotatedThenGetRotatedIdentityReturnsIdentity(): void
	{
		$hash = hash('sha256', 'some-token-id');
		$this->detector->recordRotated($hash, 'client-abc', 'admin@example.test');

		$this->assertSame(
			['client_id' => 'client-abc', 'user_id' => 'admin@example.test'],
			$this->detector->getRotatedIdentity($hash),
		);
	}

	public function testGetRotatedIdentityReturnsNullForUnseenHash(): void
	{
		$this->assertNull($this->detector->getRotatedIdentity('unknown-hash'));
	}

	public function testGetRotatedIdentityReturnsNullWhenStoredDataMalformed(): void
	{
		(new OAuthStateStore($this->dir))->put('replay', 'some-hash', ['only_client_id' => 'missing-user'], 60);

		$this->assertNull($this->detector->getRotatedIdentity('some-hash'));
	}
}
