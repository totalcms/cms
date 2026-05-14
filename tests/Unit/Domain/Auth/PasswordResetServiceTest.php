<?php

namespace Tests\Unit\Domain\Auth;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Auth\Service\AuthTokenService;
use TotalCMS\Domain\Auth\Service\PasswordResetService;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;
use TotalCMS\Support\OperationResult;

final class PasswordResetServiceTest extends TestCase
{
	private PasswordResetService $service;
	private \PHPUnit\Framework\MockObject\MockObject $tokenService;
	private \PHPUnit\Framework\MockObject\MockObject $userValidator;
	private \PHPUnit\Framework\MockObject\MockObject $objectUpdater;
	private \PHPUnit\Framework\MockObject\MockObject $config;
	private \PHPUnit\Framework\MockObject\MockObject $loggerFactory;

	protected function setUp(): void
	{
		$this->tokenService  = $this->createMock(AuthTokenService::class);
		$this->userValidator = $this->createMock(UserValidationService::class);
		$this->objectUpdater = $this->createMock(ObjectUpdater::class);
		$this->config        = $this->createMock(Config::class);
		$this->loggerFactory = $this->createMock(LoggerFactory::class);

		// Mock logger factory chain
		$this->loggerFactory->method('addFileHandler')->willReturnSelf();
		$this->loggerFactory->method('createLogger')->willReturn(
			$this->createMock(\Psr\Log\LoggerInterface::class)
		);

		$this->service = new PasswordResetService(
			$this->tokenService,
			$this->userValidator,
			$this->objectUpdater,
			$this->config,
			$this->loggerFactory
		);
	}

	public function testGenerateTokenDelegatesToTokenService(): void
	{
		$this->tokenService->expects($this->once())
			->method('generateToken')
			->willReturn(str_repeat('a', 64));

		$token = $this->service->generateToken();

		$this->assertSame(str_repeat('a', 64), $token);
	}

	public function testCreateResetTokenSucceedsForExistingUser(): void
	{
		$email      = 'user@example.com';
		$collection = 'users';

		$userData = new ObjectData('user-123', [
			'email' => $email,
			'name'  => 'Test User',
		]);

		$this->userValidator->expects($this->once())
			->method('findUserByEmail')
			->with($email, $collection)
			->willReturn($userData);

		// Mock config — 30 min expiry, so TTL = 1800 seconds
		$this->config->auth = ['resetTokenExpiry' => 30];

		// Token service is asked to create a 'reset' scoped token
		$this->tokenService->expects($this->once())
			->method('createToken')
			->with('reset', $email, $collection, 1800)
			->willReturn(OperationResult::success('Token created.', ['token' => 'fake-token-abc']));

		$result = $this->service->createResetToken($email, $collection);

		$this->assertTrue($result->success);
		$this->assertArrayHasKey('token', $result->data);
		$this->assertSame('fake-token-abc', $result->data['token']);
		$this->assertStringContainsString('created successfully', $result->message);
	}

	public function testCreateResetTokenHandlesNonExistentUserSafely(): void
	{
		$email      = 'nonexistent@example.com';
		$collection = 'users';

		// User not found — findUserByEmail returns null
		$this->userValidator->expects($this->once())
			->method('findUserByEmail')
			->with($email, $collection)
			->willReturn(null);

		// Token service must not be called when the user doesn't exist
		$this->tokenService->expects($this->never())
			->method('createToken');

		$result = $this->service->createResetToken($email, $collection);

		// Still returns success to prevent user enumeration
		$this->assertTrue($result->success);
		$this->assertArrayNotHasKey('token', $result->data);
		$this->assertStringContainsString('If an account exists', $result->message);
	}

	public function testCreateResetTokenHandlesStorageFailure(): void
	{
		$email      = 'user@example.com';
		$collection = 'users';

		$userData = new ObjectData('user-123', ['email' => $email]);
		$this->userValidator->method('findUserByEmail')->willReturn($userData);

		$this->config->auth = ['resetTokenExpiry' => 30];

		// Token service returns failure
		$this->tokenService->expects($this->once())
			->method('createToken')
			->willReturn(OperationResult::failure('Unable to create token. Please try again.'));

		$result = $this->service->createResetToken($email, $collection);

		$this->assertFalse($result->success);
		$this->assertStringContainsString('Unable to create', $result->message);
	}

	public function testCreateResetTokenUsesConfiguredExpiry(): void
	{
		$email      = 'user@example.com';
		$collection = 'users';

		$userData = new ObjectData('user-123', ['email' => $email]);
		$this->userValidator->method('findUserByEmail')->willReturn($userData);

		// Config: 60 minutes = 3600 seconds
		$this->config->auth = ['resetTokenExpiry' => 60];

		// Token service should be invoked with the converted TTL
		$this->tokenService->expects($this->once())
			->method('createToken')
			->with('reset', $email, $collection, 3600)
			->willReturn(OperationResult::success('Token created.', ['token' => 'fake-token']));

		$result = $this->service->createResetToken($email, $collection);

		$this->assertTrue($result->success);
	}

	public function testValidateTokenDelegatesToTokenServiceWithResetScope(): void
	{
		$token = 'valid-token-123';

		$this->tokenService->expects($this->once())
			->method('validateToken')
			->with('reset', $token)
			->willReturn(OperationResult::success('Token is valid.', [
				'email'      => 'user@example.com',
				'collection' => 'users',
			]));

		$result = $this->service->validateToken($token);

		$this->assertTrue($result->success);
		$this->assertSame('user@example.com', $result->data['email']);
		$this->assertSame('users', $result->data['collection']);
	}

	public function testValidateTokenFailsForInvalidToken(): void
	{
		$token = 'invalid-token';

		$this->tokenService->expects($this->once())
			->method('validateToken')
			->with('reset', $token)
			->willReturn(OperationResult::failure('Invalid or expired token.'));

		$result = $this->service->validateToken($token);

		$this->assertFalse($result->success);
		$this->assertStringContainsString('Invalid or expired', $result->message);
	}

	public function testResetPasswordSucceedsWithValidToken(): void
	{
		$token       = 'valid-token';
		$newPassword = 'newSecurePassword123!';

		$this->tokenService->method('validateToken')
			->with('reset', $token)
			->willReturn(OperationResult::success('Token is valid.', [
				'email'      => 'user@example.com',
				'collection' => 'users',
			]));

		// Mock user fetch - create mock ObjectData with stubbed toArray()
		$userData     = $this->createMock(ObjectData::class);
		$userData->id = 'user-123';
		$userData->method('toArray')->willReturn([
			'id'       => 'user-123',
			'email'    => 'user@example.com',
			'password' => 'old-hashed-password',
		]);

		$this->userValidator->method('findUserByEmail')->willReturn($userData);

		// Mock password update — verify the new password was hashed
		$this->objectUpdater->expects($this->once())
			->method('updateObject')
			->with(
				'users',
				'user-123',
				$this->callback(fn ($data): bool => isset($data['password']) && password_verify('newSecurePassword123!', $data['password']))
			);

		// Mock token invalidation — both the token and the latest pointer get cleared
		$this->tokenService->expects($this->once())
			->method('invalidateToken')
			->with('reset', $token);

		$this->tokenService->expects($this->once())
			->method('invalidateLatest')
			->with('reset', 'user@example.com', 'users');

		$result = $this->service->resetPassword($token, $newPassword);

		$this->assertTrue($result->success);
		$this->assertStringContainsString('successful', $result->message);
	}

	public function testResetPasswordFailsWithInvalidToken(): void
	{
		$token       = 'invalid-token';
		$newPassword = 'newPassword123';

		$this->tokenService->method('validateToken')
			->willReturn(OperationResult::failure('Invalid or expired token.'));

		$this->userValidator->expects($this->never())
			->method('findUserByEmail');

		$this->objectUpdater->expects($this->never())
			->method('updateObject');

		$result = $this->service->resetPassword($token, $newPassword);

		$this->assertFalse($result->success);
		$this->assertStringContainsString('Invalid or expired', $result->message);
	}

	public function testResetPasswordFailsWhenUserNotFound(): void
	{
		$token       = 'valid-token';
		$newPassword = 'newPassword123';

		$this->tokenService->method('validateToken')
			->willReturn(OperationResult::success('Token is valid.', [
				'email'      => 'deleted@example.com',
				'collection' => 'users',
			]));

		// User not found
		$this->userValidator->method('findUserByEmail')->willReturn(null);

		$this->objectUpdater->expects($this->never())
			->method('updateObject');

		$result = $this->service->resetPassword($token, $newPassword);

		$this->assertFalse($result->success);
		$this->assertStringContainsString('not found', $result->message);
	}

	public function testResetPasswordHandlesUpdateException(): void
	{
		$token       = 'valid-token';
		$newPassword = 'newPassword123';

		$this->tokenService->method('validateToken')
			->willReturn(OperationResult::success('Token is valid.', [
				'email'      => 'user@example.com',
				'collection' => 'users',
			]));

		// Mock user fetch
		$userData     = $this->createMock(ObjectData::class);
		$userData->id = 'user-123';
		$userData->method('toArray')->willReturn([
			'id'    => 'user-123',
			'email' => 'user@example.com',
		]);

		$this->userValidator->method('findUserByEmail')->willReturn($userData);

		// Mock update failure
		$this->objectUpdater->expects($this->once())
			->method('updateObject')
			->willThrowException(new \Exception('Database error'));

		$result = $this->service->resetPassword($token, $newPassword);

		$this->assertFalse($result->success);
		$this->assertStringContainsString('Failed to update', $result->message);
	}
}
