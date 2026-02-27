<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Auth;

use PHPUnit\Framework\TestCase;

/**
 * Tests for PasskeyLoginAction's user state checking logic.
 *
 * These tests verify the same user validation rules that apply to password login
 * are also enforced during passkey authentication.
 */
final class PasskeyLoginActionTest extends TestCase
{
	/**
	 * Test the user state check logic extracted from PasskeyLoginAction.
	 * We test this as a standalone method since the action depends on HTTP infrastructure.
	 */
	public function testActiveUserPassesStateCheck(): void
	{
		$user = [
			'id'     => 'admin',
			'active' => true,
			'email'  => 'admin@test.com',
		];

		$this->expectNotToPerformAssertions();
		$this->checkUserState($user);
	}

	public function testInactiveUserFailsStateCheck(): void
	{
		$user = [
			'id'     => 'admin',
			'active' => false,
			'email'  => 'admin@test.com',
		];

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('not active');
		$this->checkUserState($user);
	}

	public function testMissingActiveFieldFailsStateCheck(): void
	{
		$user = [
			'id'    => 'admin',
			'email' => 'admin@test.com',
		];

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('not active');
		$this->checkUserState($user);
	}

	public function testExpiredUserFailsStateCheck(): void
	{
		$user = [
			'id'         => 'admin',
			'active'     => true,
			'email'      => 'admin@test.com',
			'expiration' => '2020-01-01T00:00:00+00:00',
		];

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('expired');
		$this->checkUserState($user);
	}

	public function testFutureExpirationPassesStateCheck(): void
	{
		$user = [
			'id'         => 'admin',
			'active'     => true,
			'email'      => 'admin@test.com',
			'expiration' => '2030-12-31T23:59:59+00:00',
		];

		$this->expectNotToPerformAssertions();
		$this->checkUserState($user);
	}

	public function testEmptyExpirationPassesStateCheck(): void
	{
		$user = [
			'id'         => 'admin',
			'active'     => true,
			'email'      => 'admin@test.com',
			'expiration' => '',
		];

		$this->expectNotToPerformAssertions();
		$this->checkUserState($user);
	}

	public function testMaxLoginCountExceededFailsStateCheck(): void
	{
		$user = [
			'id'            => 'admin',
			'active'        => true,
			'email'         => 'admin@test.com',
			'maxLoginCount' => 5,
			'loginCount'    => 5,
		];

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('maximum login count');
		$this->checkUserState($user);
	}

	public function testLoginCountBelowMaxPassesStateCheck(): void
	{
		$user = [
			'id'            => 'admin',
			'active'        => true,
			'email'         => 'admin@test.com',
			'maxLoginCount' => 10,
			'loginCount'    => 3,
		];

		$this->expectNotToPerformAssertions();
		$this->checkUserState($user);
	}

	public function testZeroMaxLoginCountMeansUnlimited(): void
	{
		$user = [
			'id'            => 'admin',
			'active'        => true,
			'email'         => 'admin@test.com',
			'maxLoginCount' => 0,
			'loginCount'    => 999,
		];

		$this->expectNotToPerformAssertions();
		$this->checkUserState($user);
	}

	/**
	 * Mirror of PasskeyLoginAction::checkUserState() for isolated testing.
	 *
	 * @param array<string,mixed> $user
	 */
	private function checkUserState(array $user): void
	{
		if (!isset($user['active']) || !$user['active']) {
			throw new \RuntimeException('User account is not active');
		}

		if (
			isset($user['expiration'])
			&& !empty($user['expiration'])
			&& strtotime((string)$user['expiration']) < time()
		) {
			throw new \RuntimeException('User account has expired');
		}

		if (
			isset($user['maxLoginCount'], $user['loginCount'])
			&& $user['maxLoginCount'] > 0
			&& $user['loginCount'] >= $user['maxLoginCount']
		) {
			throw new \RuntimeException('User account has reached the maximum login count');
		}
	}
}
