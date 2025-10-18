<?php

namespace Tests\Unit\Domain\Auth;

use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Auth\Service\PasswordResetService;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexSearcher;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

final class PasswordResetServiceTest extends TestCase
{
	private PasswordResetService $service;
	private \PHPUnit\Framework\MockObject\MockObject $cacheManager;
	private \PHPUnit\Framework\MockObject\MockObject $indexSearcher;
	private \PHPUnit\Framework\MockObject\MockObject $objectFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $objectUpdater;
	private \PHPUnit\Framework\MockObject\MockObject $config;
	private \PHPUnit\Framework\MockObject\MockObject $loggerFactory;

	protected function setUp(): void
	{
		$this->cacheManager  = $this->createMock(CacheManager::class);
		$this->indexSearcher = $this->createMock(IndexSearcher::class);
		$this->objectFetcher = $this->createMock(ObjectFetcher::class);
		$this->objectUpdater = $this->createMock(ObjectUpdater::class);
		$this->config        = $this->createMock(Config::class);
		$this->loggerFactory = $this->createMock(LoggerFactory::class);

		// Mock logger factory chain
		$this->loggerFactory->method('addFileHandler')->willReturnSelf();
		$this->loggerFactory->method('createLogger')->willReturn(
			$this->createMock(\Psr\Log\LoggerInterface::class)
		);

		$this->service = new PasswordResetService(
			$this->cacheManager,
			$this->indexSearcher,
			$this->objectFetcher,
			$this->objectUpdater,
			$this->config,
			$this->loggerFactory
		);
	}

	/**
	 * Create a Collection of objects.
	 *
	 * @param array<int,array<string,mixed>> $objects
	 */
	private function createCollection(array $objects): Collection
	{
		return new Collection($objects);
	}

	public function testGenerateTokenCreatesSecureToken(): void
	{
		$token = $this->service->generateToken();

		$this->assertIsString($token);
		$this->assertSame(64, strlen($token)); // 32 bytes * 2 hex chars
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
	}

	public function testGenerateTokenCreatesUniqueTokens(): void
	{
		$token1 = $this->service->generateToken();
		$token2 = $this->service->generateToken();

		$this->assertNotEquals($token1, $token2);
	}

	public function testCreateResetTokenSucceedsForExistingUser(): void
	{
		$email      = 'user@example.com';
		$collection = 'users';

		// Mock user search - return IndexData with user
		$indexData = $this->createCollection([['id' => 'user-123']]);

		$this->indexSearcher->expects($this->once())
			->method('searchByProperty')
			->with($collection, 'email', $email)
			->willReturn($indexData);

		// Mock user fetch
		$userData = new ObjectData('user-123', [
			'email' => $email,
			'name'  => 'Test User',
		]);

		$this->objectFetcher->expects($this->once())
			->method('fetchObject')
			->with($collection, 'user-123')
			->willReturn($userData);

		// Mock cache operations
		$this->cacheManager->expects($this->once())
			->method('getPasswordResetData')
			->willReturn(null); // No previous token

		$this->cacheManager->expects($this->exactly(2))
			->method('storePasswordResetData')
			->willReturn(true);

		// Mock config
		$this->config->auth = ['resetTokenExpiry' => 30];

		$result = $this->service->createResetToken($email, $collection);

		$this->assertTrue($result['success']);
		$this->assertArrayHasKey('token', $result);
		$this->assertSame(64, strlen($result['token']));
		$this->assertStringContainsString('created successfully', $result['message']);
	}

	public function testCreateResetTokenHandlesNonExistentUserSafely(): void
	{
		$email      = 'nonexistent@example.com';
		$collection = 'users';

		// Mock empty search result
		$indexData = $this->createCollection([]); // Empty collection

		$this->indexSearcher->expects($this->once())
			->method('searchByProperty')
			->willReturn($indexData);

		$this->objectFetcher->expects($this->never())
			->method('fetchObject');

		$this->cacheManager->expects($this->never())
			->method('storePasswordResetData');

		$result = $this->service->createResetToken($email, $collection);

		// Still returns success to prevent user enumeration
		$this->assertTrue($result['success']);
		$this->assertArrayNotHasKey('token', $result);
		$this->assertStringContainsString('If an account exists', $result['message']);
	}

	public function testCreateResetTokenInvalidatesPreviousToken(): void
	{
		$email      = 'user@example.com';
		$collection = 'users';

		// Mock user search
		$indexData = $this->createCollection([['id' => 'user-123']]);

		$this->indexSearcher->method('searchByProperty')->willReturn($indexData);

		// Mock user fetch
		$userData = new ObjectData('user-123', [
			'email' => $email,
		]);

		$this->objectFetcher->method('fetchObject')->willReturn($userData);

		// Mock previous token
		$this->cacheManager->expects($this->once())
			->method('getPasswordResetData')
			->with("latest:{$email}:{$collection}")
			->willReturn(['token' => 'old-token-123']);

		$this->cacheManager->expects($this->once())
			->method('clearPasswordResetData')
			->with("token:old-token-123");

		$this->cacheManager->expects($this->exactly(2))
			->method('storePasswordResetData')
			->willReturn(true);

		$this->config->auth = ['resetTokenExpiry' => 30];

		$result = $this->service->createResetToken($email, $collection);

		$this->assertTrue($result['success']);
	}

	public function testCreateResetTokenHandlesStorageFailure(): void
	{
		$email      = 'user@example.com';
		$collection = 'users';

		// Mock user search
		$indexData = $this->createCollection([['id' => 'user-123']]);

		$this->indexSearcher->method('searchByProperty')->willReturn($indexData);

		// Mock user fetch
		$userData = new ObjectData('user-123', [
			'email' => $email,
		]);

		$this->objectFetcher->method('fetchObject')->willReturn($userData);

		$this->cacheManager->method('getPasswordResetData')->willReturn(null);

		// Mock storage failure
		$this->cacheManager->expects($this->once())
			->method('storePasswordResetData')
			->willReturn(false);

		$this->config->auth = ['resetTokenExpiry' => 30];

		$result = $this->service->createResetToken($email, $collection);

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('Unable to create', $result['message']);
	}

	public function testCreateResetTokenUsesConfiguredExpiry(): void
	{
		$email      = 'user@example.com';
		$collection = 'users';

		// Mock user search
		$indexData = $this->createCollection([['id' => 'user-123']]);

		$this->indexSearcher->method('searchByProperty')->willReturn($indexData);

		// Mock user fetch
		$userData = new ObjectData('user-123', [
			'email' => $email,
		]);

		$this->objectFetcher->method('fetchObject')->willReturn($userData);

		$this->cacheManager->method('getPasswordResetData')->willReturn(null);

		// Expect TTL to be 60 minutes * 60 seconds
		$this->cacheManager->expects($this->exactly(2))
			->method('storePasswordResetData')
			->with(
				$this->anything(),
				$this->anything(),
				3600 // 60 minutes
			)
			->willReturn(true);

		$this->config->auth = ['resetTokenExpiry' => 60];

		$result = $this->service->createResetToken($email, $collection);

		$this->assertTrue($result['success']);
	}

	public function testValidateTokenSucceedsForValidToken(): void
	{
		$token = 'valid-token-123';

		$tokenData = [
			'email'      => 'user@example.com',
			'collection' => 'users',
			'createdAt'  => time() - 600, // 10 minutes ago
			'expiresAt'  => time() + 1200, // 20 minutes from now
		];

		$this->cacheManager->expects($this->once())
			->method('getPasswordResetData')
			->with("token:{$token}")
			->willReturn($tokenData);

		$result = $this->service->validateToken($token);

		$this->assertTrue($result['valid']);
		$this->assertSame('user@example.com', $result['email']);
		$this->assertSame('users', $result['collection']);
		$this->assertStringContainsString('valid', $result['message']);
	}

	public function testValidateTokenFailsForInvalidToken(): void
	{
		$token = 'invalid-token';

		$this->cacheManager->expects($this->once())
			->method('getPasswordResetData')
			->with("token:{$token}")
			->willReturn(null);

		$result = $this->service->validateToken($token);

		$this->assertFalse($result['valid']);
		$this->assertArrayNotHasKey('email', $result);
		$this->assertArrayNotHasKey('collection', $result);
		$this->assertStringContainsString('Invalid or expired', $result['message']);
	}

	public function testValidateTokenFailsForExpiredToken(): void
	{
		$token = 'expired-token';

		$tokenData = [
			'email'      => 'user@example.com',
			'collection' => 'users',
			'createdAt'  => time() - 3600, // 1 hour ago
			'expiresAt'  => time() - 1800, // Expired 30 minutes ago
		];

		$this->cacheManager->expects($this->once())
			->method('getPasswordResetData')
			->with("token:{$token}")
			->willReturn($tokenData);

		$this->cacheManager->expects($this->once())
			->method('clearPasswordResetData')
			->with("token:{$token}");

		$result = $this->service->validateToken($token);

		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('expired', $result['message']);
	}

	public function testResetPasswordSucceedsWithValidToken(): void
	{
		$token       = 'valid-token';
		$newPassword = 'newSecurePassword123!';

		// Mock valid token
		$tokenData = [
			'email'      => 'user@example.com',
			'collection' => 'users',
			'createdAt'  => time(),
			'expiresAt'  => time() + 1800,
		];

		$this->cacheManager->method('getPasswordResetData')
			->with("token:{$token}")
			->willReturn($tokenData);

		// Mock user search
		$indexData = $this->createCollection([['id' => 'user-123']]);

		$this->indexSearcher->method('searchByProperty')->willReturn($indexData);

		// Mock user fetch - create mock ObjectData with stubbed toArray()
		$userData     = $this->createMock(ObjectData::class);
		$userData->id = 'user-123';
		$userData->method('toArray')->willReturn([
			'id'       => 'user-123',
			'email'    => 'user@example.com',
			'password' => 'old-hashed-password',
		]);

		$this->objectFetcher->method('fetchObject')->willReturn($userData);

		// Mock password update
		$this->objectUpdater->expects($this->once())
			->method('updateObject')
			->with(
				'users',
				'user-123',
				$this->callback(function ($data) {
					return isset($data['password']) && password_verify('newSecurePassword123!', $data['password']);
				})
			);

		// Mock token invalidation
		$this->cacheManager->expects($this->exactly(2))
			->method('clearPasswordResetData');

		$result = $this->service->resetPassword($token, $newPassword);

		$this->assertTrue($result['success']);
		$this->assertStringContainsString('successful', $result['message']);
	}

	public function testResetPasswordFailsWithInvalidToken(): void
	{
		$token       = 'invalid-token';
		$newPassword = 'newPassword123';

		$this->cacheManager->method('getPasswordResetData')
			->willReturn(null);

		$this->objectFetcher->expects($this->never())
			->method('fetchObject');

		$this->objectUpdater->expects($this->never())
			->method('updateObject');

		$result = $this->service->resetPassword($token, $newPassword);

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('Invalid or expired', $result['message']);
	}

	public function testResetPasswordFailsWhenUserNotFound(): void
	{
		$token       = 'valid-token';
		$newPassword = 'newPassword123';

		// Mock valid token
		$tokenData = [
			'email'      => 'deleted@example.com',
			'collection' => 'users',
			'createdAt'  => time(),
			'expiresAt'  => time() + 1800,
		];

		$this->cacheManager->method('getPasswordResetData')
			->willReturn($tokenData);

		// Mock user not found
		$indexData = $this->createCollection([]); // Empty collection

		$this->indexSearcher->method('searchByProperty')->willReturn($indexData);

		$this->objectUpdater->expects($this->never())
			->method('updateObject');

		$result = $this->service->resetPassword($token, $newPassword);

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('not found', $result['message']);
	}

	public function testResetPasswordHandlesUpdateException(): void
	{
		$token       = 'valid-token';
		$newPassword = 'newPassword123';

		// Mock valid token
		$tokenData = [
			'email'      => 'user@example.com',
			'collection' => 'users',
			'createdAt'  => time(),
			'expiresAt'  => time() + 1800,
		];

		$this->cacheManager->method('getPasswordResetData')
			->willReturn($tokenData);

		// Mock user search
		$indexData = $this->createCollection([['id' => 'user-123']]);

		$this->indexSearcher->method('searchByProperty')->willReturn($indexData);

		// Mock user fetch - create mock ObjectData with stubbed toArray()
		$userData     = $this->createMock(ObjectData::class);
		$userData->id = 'user-123';
		$userData->method('toArray')->willReturn([
			'id'    => 'user-123',
			'email' => 'user@example.com',
		]);

		$this->objectFetcher->method('fetchObject')->willReturn($userData);

		// Mock update failure
		$this->objectUpdater->expects($this->once())
			->method('updateObject')
			->willThrowException(new \Exception('Database error'));

		$result = $this->service->resetPassword($token, $newPassword);

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('Failed to update', $result['message']);
	}
}
