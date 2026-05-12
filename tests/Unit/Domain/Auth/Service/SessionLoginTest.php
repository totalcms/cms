<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Auth\Service;

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
}
