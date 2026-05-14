<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Auth;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Auth\Service\AuthTokenService;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Factory\LoggerFactory;

final class AuthTokenServiceTest extends TestCase
{
	private AuthTokenService $service;
	private \PHPUnit\Framework\MockObject\MockObject $cacheManager;

	protected function setUp(): void
	{
		$this->cacheManager = $this->createMock(CacheManager::class);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn(
			$this->createMock(\Psr\Log\LoggerInterface::class)
		);

		$this->service = new AuthTokenService($this->cacheManager, $loggerFactory);
	}

	public function testGenerateTokenIsSecure64CharHex(): void
	{
		$token = $this->service->generateToken();

		$this->assertSame(64, strlen($token));
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
	}

	public function testGenerateTokensAreUnique(): void
	{
		$this->assertNotEquals(
			$this->service->generateToken(),
			$this->service->generateToken(),
		);
	}

	public function testCreateTokenStoresWithScopedKeysAndReturnsToken(): void
	{
		// No previous token — getPasswordResetData returns null on the latest pointer.
		$this->cacheManager->method('getPasswordResetData')->willReturn(null);

		// Two store calls expected: the token data and the "latest" pointer,
		// both keys must include the scope.
		$storeCalls = [];
		$this->cacheManager->expects($this->exactly(2))
			->method('storePasswordResetData')
			->willReturnCallback(function (string $key, array $data, int $ttl) use (&$storeCalls): bool {
				$storeCalls[] = ['key' => $key, 'data' => $data, 'ttl' => $ttl];

				return true;
			});

		$result = $this->service->createToken('verify', 'a@b.test', 'members', 3600);

		$this->assertTrue($result->success);
		$this->assertArrayHasKey('token', $result->data);

		$token = (string)$result->data['token'];

		// First call stores the token itself
		$this->assertSame("verify:token:{$token}", $storeCalls[0]['key']);
		$this->assertSame('verify', $storeCalls[0]['data']['scope']);
		$this->assertSame('a@b.test', $storeCalls[0]['data']['email']);
		$this->assertSame('members', $storeCalls[0]['data']['collection']);
		$this->assertSame(3600, $storeCalls[0]['ttl']);

		// Second call stores the "latest" pointer
		$this->assertSame('verify:latest:a@b.test:members', $storeCalls[1]['key']);
		$this->assertSame($token, $storeCalls[1]['data']['token']);
	}

	public function testCreateTokenInvalidatesPreviousTokenForSameUserAndScope(): void
	{
		// Latest pointer says there was a previous token "old-token-123".
		$this->cacheManager->method('getPasswordResetData')
			->willReturnMap([
				['verify:latest:a@b.test:members', ['token' => 'old-token-123']],
			]);

		// Old token's key gets cleared first.
		$this->cacheManager->expects($this->once())
			->method('clearPasswordResetData')
			->with('verify:token:old-token-123');

		$this->cacheManager->method('storePasswordResetData')->willReturn(true);

		$result = $this->service->createToken('verify', 'a@b.test', 'members', 3600);

		$this->assertTrue($result->success);
	}

	public function testCreateTokenReturnsFailureWhenStorageRejects(): void
	{
		$this->cacheManager->method('getPasswordResetData')->willReturn(null);
		$this->cacheManager->method('storePasswordResetData')->willReturn(false);

		$result = $this->service->createToken('verify', 'a@b.test', 'members', 3600);

		$this->assertFalse($result->success);
	}

	public function testValidateTokenSucceedsForFreshToken(): void
	{
		$token = 'tok-abc';

		$this->cacheManager->method('getPasswordResetData')
			->with("verify:token:{$token}")
			->willReturn([
				'email'      => 'a@b.test',
				'collection' => 'members',
				'scope'      => 'verify',
				'createdAt'  => time() - 60,
				'expiresAt'  => time() + 3600,
			]);

		$result = $this->service->validateToken('verify', $token);

		$this->assertTrue($result->success);
		$this->assertSame('a@b.test', $result->data['email']);
		$this->assertSame('members', $result->data['collection']);
	}

	public function testValidateTokenFailsForMissingToken(): void
	{
		$this->cacheManager->method('getPasswordResetData')->willReturn(null);

		$result = $this->service->validateToken('verify', 'tok-missing');

		$this->assertFalse($result->success);
	}

	public function testValidateTokenFailsForExpiredToken(): void
	{
		$token = 'tok-expired';

		$this->cacheManager->method('getPasswordResetData')
			->willReturn([
				'email'      => 'a@b.test',
				'collection' => 'members',
				'scope'      => 'verify',
				'createdAt'  => time() - 7200,
				'expiresAt'  => time() - 60,
			]);

		// Expired tokens should be eagerly cleared
		$this->cacheManager->expects($this->once())
			->method('clearPasswordResetData')
			->with("verify:token:{$token}");

		$result = $this->service->validateToken('verify', $token);

		$this->assertFalse($result->success);
		$this->assertStringContainsString('expired', $result->message);
	}

	public function testValidateTokenRejectsScopeMismatch(): void
	{
		// Stored under 'verify' scope, but validating as 'reset'. Even though
		// the cache key would differ in normal usage, we defensively check
		// the embedded scope to handle a future bug where someone passed the
		// wrong scope.
		$this->cacheManager->method('getPasswordResetData')
			->willReturn([
				'email'      => 'a@b.test',
				'collection' => 'members',
				'scope'      => 'verify',
				'expiresAt'  => time() + 3600,
			]);

		$result = $this->service->validateToken('reset', 'tok-confused');

		$this->assertFalse($result->success);
	}

	public function testInvalidateTokenClearsTheScopedKey(): void
	{
		$this->cacheManager->expects($this->once())
			->method('clearPasswordResetData')
			->with('verify:token:tok-abc');

		$this->service->invalidateToken('verify', 'tok-abc');
	}

	public function testInvalidateLatestClearsThePointer(): void
	{
		$this->cacheManager->expects($this->once())
			->method('clearPasswordResetData')
			->with('verify:latest:a@b.test:members');

		$this->service->invalidateLatest('verify', 'a@b.test', 'members');
	}
}
