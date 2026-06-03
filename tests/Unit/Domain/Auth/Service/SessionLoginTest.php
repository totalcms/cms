<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Auth\Service;

use Odan\Session\MemorySession;
use Odan\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Auth\Service\SessionLogin;
use TotalCMS\Domain\Session\SessionKeys;

final class SessionLoginTest extends TestCase
{
	public function testEstablishWritesAllFourSessionKeys(): void
	{
		$session = $this->createMock(SessionInterface::class);

		$captured = [];
		$session->method('set')
			->willReturnCallback(static function (string $key, mixed $value) use (&$captured): void {
				$captured[$key] = $value;
			});

		(new SessionLogin($session))->establish('alice', 'members');

		$this->assertSame([
			SessionKeys::AUTH_USER             => 'alice',
			SessionKeys::AUTH_COLLECTION       => 'members',
			SessionKeys::AUTH_PERSISTENT_LOGIN => false,
			SessionKeys::LICENSE_CHECK_DUE     => true,
		], $captured);
	}

	public function testPersistentFlagPassesThrough(): void
	{
		$session  = $this->createMock(SessionInterface::class);
		$captured = [];
		$session->method('set')
			->willReturnCallback(static function (string $key, mixed $value) use (&$captured): void {
				$captured[$key] = $value;
			});

		(new SessionLogin($session))->establish('alice', 'members', true);

		$this->assertTrue($captured[SessionKeys::AUTH_PERSISTENT_LOGIN]);
	}

	public function testEstablishRegeneratesTheSessionId(): void
	{
		// MemorySession implements SessionManagerInterface, so establish() must
		// regenerate the id at the login privilege boundary (session fixation).
		$session = new MemorySession();
		$session->set('seed', 'pre-login-state');
		$before = $session->getId();

		(new SessionLogin($session))->establish('alice', 'members');

		$this->assertNotSame($before, $session->getId(), 'session id should be regenerated on login');
		$this->assertSame('alice', $session->get(SessionKeys::AUTH_USER));
	}
}
