<?php

namespace Tests\Unit\Domain\Auth\Service;

use Odan\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Auth\Service\LoginService;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

final class AccessManagerTest extends TestCase
{
	private AccessManager $accessManager;
	private \PHPUnit\Framework\MockObject\MockObject $session;
	private Config $config;
	private \PHPUnit\Framework\MockObject\MockObject $userValidator;
	private \PHPUnit\Framework\MockObject\MockObject $loggerFactory;

	protected function setUp(): void
	{
		$this->session       = $this->createMock(SessionInterface::class);
		$this->config        = $this->createTestConfig();
		$this->userValidator = $this->createMock(UserValidationService::class);
		$this->loggerFactory = $this->createMock(LoggerFactory::class);

		// Mock logger factory chain
		$logger = $this->createMock(LoggerInterface::class);
		$this->loggerFactory->method('addFileHandler')
			->with(LoginService::ACCESS_LOG)
			->willReturnSelf();
		$this->loggerFactory->method('createLogger')
			->with('access')
			->willReturn($logger);

		$this->accessManager = new AccessManager(
			$this->session,
			$this->config,
			$this->userValidator,
			$this->loggerFactory
		);
	}

	// ==================== Session Has User Tests ====================

	public function testSessionHasUserReturnsTrueWhenBothKeysPresent(): void
	{
		$this->session->expects($this->exactly(2))
			->method('has')
			->willReturnMap([
				[SessionKeys::AUTH_USER, true],
				[SessionKeys::AUTH_COLLECTION, true],
			]);

		$this->assertTrue($this->accessManager->sessionHasUser());
	}

	public function testSessionHasUserReturnsFalseWhenUserKeyMissing(): void
	{
		$this->session->expects($this->once())
			->method('has')
			->with(SessionKeys::AUTH_USER)
			->willReturn(false);

		$this->assertFalse($this->accessManager->sessionHasUser());
	}

	public function testSessionHasUserReturnsFalseWhenCollectionKeyMissing(): void
	{
		$this->session->method('has')
			->willReturnMap([
				[SessionKeys::AUTH_USER, true],
				[SessionKeys::AUTH_COLLECTION, false],
			]);

		$this->assertFalse($this->accessManager->sessionHasUser());
	}

	// ==================== User Logged In Tests ====================

	public function testUserLoggedInReturnsFalseWhenNoSession(): void
	{
		$this->session->method('has')->willReturn(false);

		$this->assertFalse($this->accessManager->userLoggedIn());
	}

	public function testUserLoggedInReturnsTrueForSuperAdmin(): void
	{
		$this->setupSessionWithUser('admin-user', 'auth');

		$this->userValidator->expects($this->once())
			->method('isSuperAdmin')
			->with('admin-user')
			->willReturn(true);

		$this->assertTrue($this->accessManager->userLoggedIn());
	}

	public function testUserLoggedInValidatesUserInCollection(): void
	{
		$this->setupSessionWithUser('regular-user', 'auth');

		$this->userValidator->method('isSuperAdmin')->willReturn(false);
		$this->userValidator->expects($this->once())
			->method('validateUserById')
			->with('regular-user', 'auth')
			->willReturn(['id' => 'regular-user', 'username' => 'testuser']);

		$this->assertTrue($this->accessManager->userLoggedIn());
	}

	public function testUserLoggedInUsesDefaultCollectionWhenEmpty(): void
	{
		$this->setupSessionWithUser('user', 'auth');

		$this->userValidator->method('isSuperAdmin')->willReturn(false);
		$this->userValidator->expects($this->once())
			->method('validateUserById')
			->with('user', 'auth') // Should use default 'auth' collection
			->willReturn(['id' => 'user', 'username' => 'testuser']);

		$this->assertTrue($this->accessManager->userLoggedIn(''));
	}

	public function testUserLoggedInReturnsFalseWhenCollectionMismatch(): void
	{
		$this->setupSessionWithUser('user', 'auth');

		$this->userValidator->method('isSuperAdmin')->willReturn(false);

		// User is in 'auth' collection but checking for 'custom' collection
		$this->assertFalse($this->accessManager->userLoggedIn('custom'));
	}

	public function testUserLoggedInHandlesValidationException(): void
	{
		$this->setupSessionWithUser('user', 'auth');

		$this->userValidator->method('isSuperAdmin')->willReturn(false);
		$this->userValidator->method('validateUserById')
			->willThrowException(new \Exception('User not found'));

		$this->assertFalse($this->accessManager->userLoggedIn());
	}

	// ==================== User Has Access Tests ====================

	public function testUserHasAccessReturnsFalseWhenNotLoggedIn(): void
	{
		$this->session->method('has')->willReturn(false);

		$this->assertFalse($this->accessManager->userHasAccess([], 'auth'));
	}

	public function testUserHasAccessReturnsTrueForSuperAdmin(): void
	{
		$this->setupSessionWithUser('admin', 'auth');

		$this->userValidator->expects($this->atLeastOnce())
			->method('isSuperAdmin')
			->willReturn(true);

		$this->assertTrue($this->accessManager->userHasAccess(['editor', 'admin']));
	}

	public function testUserHasAccessValidatesEmptyGroups(): void
	{
		$this->setupSessionWithUser('user', 'auth');

		$this->userValidator->method('isSuperAdmin')->willReturn(false);
		$this->userValidator->method('validateUserById')->willReturn(['id' => 'user']);

		// Empty groups should just check if user is logged in
		$this->assertTrue($this->accessManager->userHasAccess([], 'auth'));
	}

	public function testUserHasAccessValidatesStringGroup(): void
	{
		$this->setupSessionWithUser('user', 'auth');

		$this->userValidator->method('isSuperAdmin')->willReturn(false);
		$this->userValidator->method('validateUserById')->willReturn(['id' => 'user']);
		$this->userValidator->expects($this->once())
			->method('validateUserInGroups')
			->with('user', ['editor'], 'auth')
			->willReturn(true);

		$this->assertTrue($this->accessManager->userHasAccess('editor', 'auth'));
	}

	public function testUserHasAccessValidatesArrayGroups(): void
	{
		$this->setupSessionWithUser('user', 'auth');

		$this->userValidator->method('isSuperAdmin')->willReturn(false);
		$this->userValidator->method('validateUserById')->willReturn(['id' => 'user']);
		$this->userValidator->expects($this->once())
			->method('validateUserInGroups')
			->with('user', ['editor', 'admin'], 'auth')
			->willReturn(true);

		$this->assertTrue($this->accessManager->userHasAccess(['editor', 'admin'], 'auth'));
	}

	public function testUserHasAccessReturnsFalseWhenNotInGroups(): void
	{
		$this->setupSessionWithUser('user', 'auth');

		$this->userValidator->method('isSuperAdmin')->willReturn(false);
		$this->userValidator->method('validateUserInGroups')->willReturn(false);

		$this->assertFalse($this->accessManager->userHasAccess(['admin'], 'auth'));
	}

	public function testUserHasAccessHandlesValidationException(): void
	{
		$this->setupSessionWithUser('user', 'auth');

		$this->userValidator->method('isSuperAdmin')->willReturn(false);
		$this->userValidator->method('validateUserInGroups')
			->willThrowException(new \Exception('Group validation failed'));

		$this->assertFalse($this->accessManager->userHasAccess(['admin'], 'auth'));
	}

	// ==================== User Data Tests ====================

	public function testUserDataReturnsEmptyWhenNoSession(): void
	{
		$this->session->method('has')->willReturn(false);

		$this->assertEquals([], $this->accessManager->userData());
	}

	public function testUserDataReturnsUserInformation(): void
	{
		$this->setupSessionWithUser('user-123', 'auth');

		$expectedUserData = [
			'id'       => 'user-123',
			'username' => 'testuser',
			'email'    => 'test@example.com',
		];

		$this->userValidator->method('validateUserById')
			->with('user-123', 'auth')
			->willReturn($expectedUserData);

		$userData = $this->accessManager->userData();

		$this->assertEquals('user-123', $userData['id']);
		$this->assertEquals('testuser', $userData['username']);
		$this->assertEquals('test@example.com', $userData['email']);
		$this->assertEquals('auth', $userData['collection']);
	}

	public function testUserDataReturnsEmptyOnValidationException(): void
	{
		$this->setupSessionWithUser('user-123', 'auth');

		$this->userValidator->method('validateUserById')
			->willThrowException(new \Exception('User not found'));

		$this->assertEquals([], $this->accessManager->userData());
	}

	// ==================== Restrict Page Access Tests ====================

	public function testRestrictPageAccessAllowsAccessWhenLoggedIn(): void
	{
		$_SERVER['REQUEST_URI']  = '/admin/dashboard';
		$_SERVER['HTTP_REFERER'] = 'http://example.com';

		$this->setupSessionWithUser('user', 'auth');

		$this->userValidator->method('isSuperAdmin')->willReturn(false);
		$this->userValidator->method('validateUserById')->willReturn(['id' => 'user']);
		$this->userValidator->method('validateUserInGroups')->willReturn(true);

		$this->session->expects($this->atLeastOnce())
			->method('set');

		$result = $this->accessManager->restrictPageAccess(['editor'], 'auth');

		$this->assertFalse($result); // False means access allowed
	}

	public function testRestrictPageAccessRedirectsWhenNoSession(): void
	{
		$_SERVER['REQUEST_URI']  = '/admin/dashboard';
		$_SERVER['HTTP_REFERER'] = 'http://example.com';

		$this->session->method('has')->willReturn(false);

		$this->session->expects($this->atLeastOnce())
			->method('set');

		// Will call redirectToLogin which uses header()
		// We can't easily test header() in unit tests, but we can verify the return value
		$result = $this->accessManager->restrictPageAccess(['editor'], 'auth');

		$this->assertTrue($result); // True means access restricted
	}

	public function testRestrictPageAccessRedirectsWhenNoGroupAccess(): void
	{
		$_SERVER['REQUEST_URI']  = '/admin/dashboard';
		$_SERVER['HTTP_REFERER'] = 'http://example.com';

		$this->setupSessionWithUser('user', 'auth');

		$this->userValidator->method('isSuperAdmin')->willReturn(false);
		$this->userValidator->method('validateUserById')->willReturn(['id' => 'user']);
		$this->userValidator->method('validateUserInGroups')->willReturn(false);

		$result = $this->accessManager->restrictPageAccess(['admin'], 'auth');

		$this->assertTrue($result); // True means access restricted
	}

	// ==================== Helper Methods ====================

	private function setupSessionWithUser(string $userId, string $collection): void
	{
		$this->session->method('has')
			->willReturnMap([
				[SessionKeys::AUTH_USER, true],
				[SessionKeys::AUTH_COLLECTION, true],
			]);

		$this->session->method('get')
			->willReturnMap([
				[SessionKeys::AUTH_USER, null, $userId],
				[SessionKeys::AUTH_COLLECTION, null, $collection],
			]);
	}

	private function createTestConfig(array $authOverrides = []): Config
	{
		$defaultAuth = [
			'enable'     => true,
			'collection' => 'auth',
		];

		$settings = [
			'env'        => 'test',
			'template'   => '/tmp',
			'dashboard'  => [],
			'datadir'    => '/tmp',
			'tmpdir'     => '/tmp',
			'cachedir'   => '/tmp/cache',
			'cache'      => [],
			'logger'     => [],
			'sentry'     => [],
			'error'      => [],
			'domain'     => 'test.com',
			'url'        => 'http://test.com',
			'api'        => 'http://test.com/api',
			'locale'     => 'en_US',
			'session'    => [],
			'auth'       => array_merge($defaultAuth, $authOverrides),
			'debug'      => false,
			'notfound'   => '/404',
			'htmlclean'  => [],
			'smtp'       => [],
			'mailer'     => [],
			'timezone'   => 'UTC',
			'imageworks' => [],
		];

		return new Config($settings);
	}
}
