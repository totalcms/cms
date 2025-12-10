<?php

namespace Tests\Unit\Domain\Auth\Service;

use Odan\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Auth\Service\PersistentLoginService;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

final class PersistentLoginServiceTest extends TestCase
{
	private PersistentLoginService $service;
	private \PHPUnit\Framework\MockObject\MockObject $session;
	private Config $config;
	private \PHPUnit\Framework\MockObject\MockObject $userValidator;
	private \PHPUnit\Framework\MockObject\MockObject $loggerFactory;
	private string $tmpDir;

	protected function setUp(): void
	{
		$this->session       = $this->createMock(SessionInterface::class);
		$this->userValidator = $this->createMock(UserValidationService::class);
		$this->loggerFactory = $this->createMock(LoggerFactory::class);

		// Mock the logger factory to return a null logger
		$this->loggerFactory->method('addFileHandler')->willReturnSelf();
		$this->loggerFactory->method('createLogger')->willReturn(new NullLogger());

		// Create a temporary directory for token storage
		$this->tmpDir = sys_get_temp_dir() . '/totalcms_test_' . uniqid();
		mkdir($this->tmpDir, 0755, true);

		$this->config = $this->createTestConfig();

		$this->service = new PersistentLoginService(
			$this->session,
			$this->config,
			$this->userValidator,
			$this->loggerFactory
		);
	}

	protected function tearDown(): void
	{
		// Clean up temporary directory
		$tokenDir = $this->tmpDir . '/persistent_tokens';
		if (is_dir($tokenDir)) {
			$files = glob($tokenDir . '/*');
			if ($files) {
				foreach ($files as $file) {
					if (is_file($file)) {
						unlink($file);
					}
				}
			}
			rmdir($tokenDir);
		}
		if (is_dir($this->tmpDir)) {
			rmdir($this->tmpDir);
		}
	}

	// ==================== Has Persistent Login Tests ====================

	public function testHasPersistentLoginReturnsTrueWhenSet(): void
	{
		$this->session->expects($this->once())
			->method('get')
			->with(SessionKeys::AUTH_PERSISTENT_LOGIN, false)
			->willReturn(true);

		$this->assertTrue($this->service->hasPersistentLogin());
	}

	public function testHasPersistentLoginReturnsFalseWhenNotSet(): void
	{
		$this->session->expects($this->once())
			->method('get')
			->with(SessionKeys::AUTH_PERSISTENT_LOGIN, false)
			->willReturn(false);

		$this->assertFalse($this->service->hasPersistentLogin());
	}

	// ==================== Create Persistent Token Tests ====================

	public function testCreatePersistentTokenDoesNothingWhenNoUser(): void
	{
		$this->session->method('get')
			->willReturnMap([
				[SessionKeys::AUTH_USER, null, null],
				[SessionKeys::AUTH_COLLECTION, null, 'auth'],
			]);

		// Should not create any token files
		$this->service->createPersistentToken();

		$tokenDir = $this->tmpDir . '/persistent_tokens';
		$files    = glob($tokenDir . '/*.json');
		$this->assertEmpty($files);
	}

	public function testCreatePersistentTokenDoesNothingWhenNoCollection(): void
	{
		$this->session->method('get')
			->willReturnMap([
				[SessionKeys::AUTH_USER, null, 'user-123'],
				[SessionKeys::AUTH_COLLECTION, null, null],
			]);

		// Should not create any token files
		$this->service->createPersistentToken();

		$tokenDir = $this->tmpDir . '/persistent_tokens';
		$files    = glob($tokenDir . '/*.json');
		$this->assertEmpty($files);
	}

	public function testCreatePersistentTokenCreatesTokenFile(): void
	{
		$this->session->method('get')
			->willReturnMap([
				[SessionKeys::AUTH_USER, null, 'user-123'],
				[SessionKeys::AUTH_COLLECTION, null, 'auth'],
			]);

		$this->service->createPersistentToken();

		$tokenDir = $this->tmpDir . '/persistent_tokens';
		$files    = glob($tokenDir . '/*.json');

		$this->assertNotEmpty($files);
		$this->assertCount(1, $files);

		// Verify token file structure
		$tokenData = json_decode(file_get_contents($files[0]), true);
		$this->assertArrayHasKey('user_id', $tokenData);
		$this->assertArrayHasKey('collection', $tokenData);
		$this->assertArrayHasKey('token_hash', $tokenData);
		$this->assertArrayHasKey('created_at', $tokenData);
		$this->assertArrayHasKey('expires_at', $tokenData);

		$this->assertEquals('user-123', $tokenData['user_id']);
		$this->assertEquals('auth', $tokenData['collection']);
	}

	// ==================== Cleanup Expired Tokens Tests ====================

	public function testCleanupExpiredTokensRemovesExpiredTokens(): void
	{
		$tokenDir = $this->tmpDir . '/persistent_tokens';
		if (!is_dir($tokenDir)) {
			mkdir($tokenDir, 0755, true);
		}

		// Create an expired token
		$expiredToken = [
			'user_id'    => 'user-1',
			'collection' => 'auth',
			'token_hash' => password_hash('token', PASSWORD_DEFAULT),
			'created_at' => time() - 7200,
			'expires_at' => time() - 3600, // Expired 1 hour ago
		];
		file_put_contents($tokenDir . '/expired.json', json_encode($expiredToken));

		// Create a valid token
		$validToken = [
			'user_id'    => 'user-2',
			'collection' => 'auth',
			'token_hash' => password_hash('token', PASSWORD_DEFAULT),
			'created_at' => time(),
			'expires_at' => time() + 3600, // Expires in 1 hour
		];
		file_put_contents($tokenDir . '/valid.json', json_encode($validToken));

		$this->service->cleanupExpiredTokens();

		// Expired token should be removed
		$this->assertFileDoesNotExist($tokenDir . '/expired.json');

		// Valid token should still exist
		$this->assertFileExists($tokenDir . '/valid.json');
	}

	public function testCleanupExpiredTokensHandlesEmptyDirectory(): void
	{
		// Should not throw any exceptions
		$this->service->cleanupExpiredTokens();

		$this->assertTrue(true);
	}

	public function testCleanupExpiredTokensHandlesMalformedFiles(): void
	{
		$tokenDir = $this->tmpDir . '/persistent_tokens';
		if (!is_dir($tokenDir)) {
			mkdir($tokenDir, 0755, true);
		}

		// Create a malformed token file
		file_put_contents($tokenDir . '/malformed.json', 'not valid json{');

		// Should not throw exceptions
		$this->service->cleanupExpiredTokens();

		// Malformed file should still exist (cleanup only removes expired)
		$this->assertFileExists($tokenDir . '/malformed.json');
	}

	// ==================== Restore From Persistent Token Tests ====================

	public function testRestoreFromPersistentTokenReturnsFalseWhenAlreadyLoggedIn(): void
	{
		$this->session->expects($this->once())
			->method('has')
			->with(SessionKeys::AUTH_USER)
			->willReturn(true);

		$result = $this->service->restoreFromPersistentToken();

		$this->assertFalse($result);
	}

	public function testRestoreFromPersistentTokenReturnsFalseWhenNoCookie(): void
	{
		$this->session->method('has')
			->with(SessionKeys::AUTH_USER)
			->willReturn(false);

		// No cookie set
		$result = $this->service->restoreFromPersistentToken();

		$this->assertFalse($result);
	}

	// ==================== Has Persistent Cookie Tests ====================

	public function testHasPersistentCookieReturnsFalseWhenNoCookie(): void
	{
		// Save current state
		$savedCookie = $_COOKIE['tcms_persistent_token'] ?? null;
		unset($_COOKIE['tcms_persistent_token']);

		$result = $this->service->hasPersistentCookie();

		$this->assertFalse($result);

		// Restore state
		if ($savedCookie !== null) {
			$_COOKIE['tcms_persistent_token'] = $savedCookie;
		}
	}

	public function testHasPersistentCookieReturnsTrueWhenCookieExists(): void
	{
		// Save current state
		$savedCookie = $_COOKIE['tcms_persistent_token'] ?? null;

		// Set the persistent cookie
		$_COOKIE['tcms_persistent_token'] = 'selector:token';

		$result = $this->service->hasPersistentCookie();

		$this->assertTrue($result);

		// Restore state
		if ($savedCookie !== null) {
			$_COOKIE['tcms_persistent_token'] = $savedCookie;
		} else {
			unset($_COOKIE['tcms_persistent_token']);
		}
	}

	public function testHasPersistentCookieReturnsFalseWhenCookieIsEmpty(): void
	{
		// Save current state
		$savedCookie = $_COOKIE['tcms_persistent_token'] ?? null;

		// Set empty cookie
		$_COOKIE['tcms_persistent_token'] = '';

		$result = $this->service->hasPersistentCookie();

		$this->assertFalse($result);

		// Restore state
		if ($savedCookie !== null) {
			$_COOKIE['tcms_persistent_token'] = $savedCookie;
		} else {
			unset($_COOKIE['tcms_persistent_token']);
		}
	}

	// ==================== Has Persistent Login Or Cookie Tests ====================

	public function testHasPersistentLoginOrCookieReturnsTrueWhenSessionHasFlag(): void
	{
		$this->session->expects($this->once())
			->method('get')
			->with(SessionKeys::AUTH_PERSISTENT_LOGIN, false)
			->willReturn(true);

		$result = $this->service->hasPersistentLoginOrCookie();

		$this->assertTrue($result);
	}

	public function testHasPersistentLoginOrCookieReturnsTrueWhenCookieExists(): void
	{
		// Save current state
		$savedCookie = $_COOKIE['tcms_persistent_token'] ?? null;

		$this->session->method('get')
			->with(SessionKeys::AUTH_PERSISTENT_LOGIN, false)
			->willReturn(false);

		// Set the persistent cookie
		$_COOKIE['tcms_persistent_token'] = 'selector:token';

		$result = $this->service->hasPersistentLoginOrCookie();

		$this->assertTrue($result);

		// Restore state
		if ($savedCookie !== null) {
			$_COOKIE['tcms_persistent_token'] = $savedCookie;
		} else {
			unset($_COOKIE['tcms_persistent_token']);
		}
	}

	public function testHasPersistentLoginOrCookieReturnsFalseWhenNeitherExists(): void
	{
		// Save current state
		$savedCookie = $_COOKIE['tcms_persistent_token'] ?? null;
		unset($_COOKIE['tcms_persistent_token']);

		$this->session->method('get')
			->with(SessionKeys::AUTH_PERSISTENT_LOGIN, false)
			->willReturn(false);

		$result = $this->service->hasPersistentLoginOrCookie();

		$this->assertFalse($result);

		// Restore state
		if ($savedCookie !== null) {
			$_COOKIE['tcms_persistent_token'] = $savedCookie;
		}
	}

	// ==================== Helper Methods ====================

	private function createTestConfig(array $authOverrides = []): Config
	{
		$defaultAuth = [
			'enable'              => true,
			'collection'          => 'auth',
			'persistentLoginDays' => 30,
		];

		$settings = [
			'env'        => 'test',
			'template'   => '/tmp',
			'dashboard'  => [],
			'datadir'    => '/tmp',
			'tmpdir'     => $this->tmpDir,
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
