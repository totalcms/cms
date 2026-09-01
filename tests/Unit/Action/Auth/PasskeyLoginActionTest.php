<?php

declare(strict_types=1);

use TotalCMS\Action\Auth\PasskeyLoginAction;

// The user-state gate that runs during passkey login: the same active /
// expired / login-count rules password login enforces. A passkey that skipped
// them would let a disabled or expired account back in without a password.
//
// These tests drive the SHIPPED PasskeyLoginAction::checkUserState(). The
// previous version of this file declared its own private copy of that logic and
// tested the copy — zero references to the real class — so it stayed green no
// matter what the action did. checkUserState() is private and touches none of
// the action's five dependencies, so an instance without the constructor plus
// reflection reaches the real method with nothing mocked.

/** @param array<string,mixed> $user */
function passkeyCheckUserState(array $user): void
{
	$action = (new ReflectionClass(PasskeyLoginAction::class))->newInstanceWithoutConstructor();
	$method = new ReflectionMethod(PasskeyLoginAction::class, 'checkUserState');
	$method->invoke($action, $user);
}

describe('PasskeyLoginAction user state gate', function (): void {
	it('lets an active account through', function (): void {
		passkeyCheckUserState(['id' => 'admin', 'active' => true, 'email' => 'admin@test.com']);

		expect(true)->toBeTrue(); // reaching here is the assertion: no throw
	});

	it('refuses an account that has been deactivated', function (): void {
		expect(fn () => passkeyCheckUserState(['id' => 'admin', 'active' => false]))
			->toThrow(RuntimeException::class, 'not active');
	});

	it('refuses an account with no active flag at all', function (): void {
		// Fails closed: a user record predating the field, or one written by an
		// importer that omitted it, must not authenticate.
		expect(fn () => passkeyCheckUserState(['id' => 'admin', 'email' => 'admin@test.com']))
			->toThrow(RuntimeException::class, 'not active');
	});
});

describe('PasskeyLoginAction expiration', function (): void {
	it('refuses an expired account', function (): void {
		expect(fn () => passkeyCheckUserState([
			'id' => 'admin', 'active' => true, 'expiration' => '2020-01-01T00:00:00+00:00',
		]))->toThrow(RuntimeException::class, 'expired');
	});

	it('allows an expiration still in the future', function (): void {
		passkeyCheckUserState([
			'id' => 'admin', 'active' => true, 'expiration' => '2099-01-01T00:00:00+00:00',
		]);

		expect(true)->toBeTrue();
	});

	it('treats an empty expiration as no expiry', function (): void {
		// The field is optional; blank must mean "never expires" rather than
		// "expired at the epoch", which would lock out every account that has
		// not set one.
		passkeyCheckUserState(['id' => 'admin', 'active' => true, 'expiration' => '']);

		expect(true)->toBeTrue();
	});
});

describe('PasskeyLoginAction login count', function (): void {
	it('refuses an account that has used up its logins', function (): void {
		expect(fn () => passkeyCheckUserState([
			'id' => 'admin', 'active' => true, 'maxLoginCount' => 5, 'loginCount' => 5,
		]))->toThrow(RuntimeException::class, 'maximum login count');
	});

	it('allows an account still under its limit', function (): void {
		passkeyCheckUserState([
			'id' => 'admin', 'active' => true, 'maxLoginCount' => 5, 'loginCount' => 4,
		]);

		expect(true)->toBeTrue();
	});

	it('treats a zero maximum as unlimited', function (): void {
		// 0 is the "no limit" sentinel. Reading it as a limit would lock out
		// every account that never set one.
		passkeyCheckUserState([
			'id' => 'admin', 'active' => true, 'maxLoginCount' => 0, 'loginCount' => 100,
		]);

		expect(true)->toBeTrue();
	});

	it('ignores a login count with no maximum set', function (): void {
		passkeyCheckUserState(['id' => 'admin', 'active' => true, 'loginCount' => 999]);

		expect(true)->toBeTrue();
	});
});
