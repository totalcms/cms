<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Auth;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Auth\Service\AuthTokenService;
use TotalCMS\Domain\Auth\Service\EmailVerificationService;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;
use TotalCMS\Support\OperationResult;

final class EmailVerificationServiceTest extends TestCase
{
	private EmailVerificationService $service;
	private \PHPUnit\Framework\MockObject\MockObject $tokenService;
	private \PHPUnit\Framework\MockObject\MockObject $userValidator;
	private \PHPUnit\Framework\MockObject\MockObject $objectUpdater;
	private \PHPUnit\Framework\MockObject\MockObject $config;

	protected function setUp(): void
	{
		$this->tokenService  = $this->createMock(AuthTokenService::class);
		$this->userValidator = $this->createMock(UserValidationService::class);
		$this->objectUpdater = $this->createMock(ObjectUpdater::class);
		$this->config        = $this->createMock(Config::class);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn(
			$this->createMock(\Psr\Log\LoggerInterface::class)
		);

		$this->service = new EmailVerificationService(
			$this->tokenService,
			$this->userValidator,
			$this->objectUpdater,
			$this->config,
			$loggerFactory,
		);
	}

	private function userMock(string $id, bool $active): ObjectData
	{
		$user     = $this->createMock(ObjectData::class);
		$user->id = $id;
		$user->method('toArray')->willReturn([
			'id'     => $id,
			'email'  => 'a@b.test',
			'active' => $active,
		]);

		return $user;
	}

	public function testCreateVerificationTokenSucceedsForExistingUser(): void
	{
		$email      = 'new@example.com';
		$collection = 'members';

		$this->userValidator->expects($this->once())
			->method('findUserByEmail')
			->with($email, $collection)
			->willReturn($this->userMock('user-1', false));

		// 24h default = 86400 seconds
		$this->config->auth = ['verificationTokenExpiry' => 1440];

		$this->tokenService->expects($this->once())
			->method('createToken')
			->with('verify', $email, $collection, 86400)
			->willReturn(OperationResult::success('Token created.', ['token' => 'verify-token-abc']));

		$result = $this->service->createVerificationToken($email, $collection);

		$this->assertTrue($result->success);
		$this->assertSame('verify-token-abc', $result->data['token']);
	}

	public function testCreateVerificationTokenFailsWhenUserDoesNotExist(): void
	{
		$this->userValidator->method('findUserByEmail')->willReturn(null);

		// Token service must not be called.
		$this->tokenService->expects($this->never())->method('createToken');

		$result = $this->service->createVerificationToken('ghost@example.com', 'members');

		$this->assertFalse($result->success);
	}

	public function testActivateUserSucceedsWithValidToken(): void
	{
		$token = 'valid-token';

		$this->tokenService->method('validateToken')
			->with('verify', $token)
			->willReturn(OperationResult::success('Token is valid.', [
				'email'      => 'a@b.test',
				'collection' => 'members',
			]));

		$this->userValidator->method('findUserByEmail')->willReturn($this->userMock('user-1', false));

		// User update must include active=true.
		$this->objectUpdater->expects($this->once())
			->method('updateObject')
			->with('members', 'user-1', $this->callback(static fn (array $d): bool => ($d['active'] ?? null) === true));

		// Token + latest pointer both invalidated.
		$this->tokenService->expects($this->once())
			->method('invalidateToken')
			->with('verify', $token);

		$this->tokenService->expects($this->once())
			->method('invalidateLatest')
			->with('verify', 'a@b.test', 'members');

		$result = $this->service->activateUser($token);

		$this->assertTrue($result->success);
		$this->assertSame('a@b.test', $result->data['email']);
		$this->assertSame('members', $result->data['collection']);
	}

	public function testActivateUserFailsWithInvalidToken(): void
	{
		$this->tokenService->method('validateToken')
			->willReturn(OperationResult::failure('Invalid or expired token.'));

		$this->objectUpdater->expects($this->never())->method('updateObject');
		$this->tokenService->expects($this->never())->method('invalidateToken');

		$result = $this->service->activateUser('bad-token');

		$this->assertFalse($result->success);
	}

	public function testActivateUserHandlesUpdateException(): void
	{
		$token = 'valid-token';

		$this->tokenService->method('validateToken')
			->willReturn(OperationResult::success('Token is valid.', [
				'email'      => 'a@b.test',
				'collection' => 'members',
			]));

		$this->userValidator->method('findUserByEmail')->willReturn($this->userMock('user-1', false));

		$this->objectUpdater->method('updateObject')
			->willThrowException(new \Exception('Disk full'));

		// Token must NOT be invalidated if the update failed — user can retry.
		$this->tokenService->expects($this->never())->method('invalidateToken');

		$result = $this->service->activateUser($token);

		$this->assertFalse($result->success);
		$this->assertStringContainsString('Failed to activate', $result->message);
	}

	public function testResendVerificationTokenIssuesNewTokenForInactiveUser(): void
	{
		$email      = 'a@b.test';
		$collection = 'members';

		$this->userValidator->method('findUserByEmail')->willReturn($this->userMock('user-1', false));

		$this->config->auth = ['verificationTokenExpiry' => 1440];

		$this->tokenService->expects($this->once())
			->method('createToken')
			->with('verify', $email, $collection, 86400)
			->willReturn(OperationResult::success('Token created.', ['token' => 'fresh-token']));

		$result = $this->service->resendVerificationToken($email, $collection);

		$this->assertTrue($result->success);
		$this->assertSame('fresh-token', $result->data['token']);
	}

	public function testResendVerificationTokenIsSilentForNonExistentUser(): void
	{
		// Anti-enumeration: should look identical to a successful resend.
		$this->userValidator->method('findUserByEmail')->willReturn(null);

		$this->tokenService->expects($this->never())->method('createToken');

		$result = $this->service->resendVerificationToken('ghost@example.com', 'members');

		// Returns generic success — no token in data
		$this->assertTrue($result->success);
		$this->assertArrayNotHasKey('token', $result->data);
	}

	public function testResendVerificationTokenIsSilentForAlreadyActiveUser(): void
	{
		// User has already verified — no email should be sent. From the
		// caller's perspective this looks identical to a non-existent user.
		$this->userValidator->method('findUserByEmail')->willReturn($this->userMock('user-1', true));

		$this->tokenService->expects($this->never())->method('createToken');

		$result = $this->service->resendVerificationToken('a@b.test', 'members');

		$this->assertTrue($result->success);
		$this->assertArrayNotHasKey('token', $result->data);
	}

	public function testResendVerificationTokenSwallowsTokenServiceFailure(): void
	{
		// If the cache layer is down, we still return generic success to
		// avoid revealing infrastructure state to the caller.
		$this->userValidator->method('findUserByEmail')->willReturn($this->userMock('user-1', false));

		$this->config->auth = ['verificationTokenExpiry' => 1440];

		$this->tokenService->method('createToken')
			->willReturn(OperationResult::failure('cache down'));

		$result = $this->service->resendVerificationToken('a@b.test', 'members');

		$this->assertTrue($result->success);
		$this->assertArrayNotHasKey('token', $result->data);
	}
}
