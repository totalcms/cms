<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\AuthThrottle;
use TotalCMS\Domain\Cache\CacheManager;

// Login attempts used to be counted in the session, so a client that dropped
// its cookie was never limited (a Nov 2026 scan made 434 checked logins in an
// hour). These pin the server-side counts that replaced it.

function authThrottleRequest(string $ip = '203.0.113.5'): ServerRequestInterface
{
	return (new Psr17Factory())->createServerRequest('POST', '/admin/login', ['REMOTE_ADDR' => $ip]);
}

/** @param array<string,mixed> $auth */
function authThrottleOver(CacheManager $cache, array $auth = []): AuthThrottle
{
	return testAuthThrottle($auth + ['maxAttempts' => 3, 'deniedTimeout' => 7], $cache);
}

beforeEach(function (): void {
	// A fresh in-memory cache per test, shared by every request in it.
	$this->cache = testMemoryCache();
});

test('a client is locked out after maxAttempts failures, without any session', function (): void {
	$throttle = authThrottleOver($this->cache);

	expect($throttle->recordLoginFailure(authThrottleRequest(), 'auth|a@example.com'))->toBe(0)
		->and($throttle->recordLoginFailure(authThrottleRequest(), 'auth|b@example.com'))->toBe(0)
		->and($throttle->loginLockedFor(authThrottleRequest()))->toBe(0);

	expect($throttle->recordLoginFailure(authThrottleRequest(), 'auth|c@example.com'))->toBe(7 * 60);

	expect($throttle->loginLockedFor(authThrottleRequest()))->toBeGreaterThan(400)
		->and($throttle->loginLockedFor(authThrottleRequest('198.51.100.9')))->toBe(0);
});

test('each lockout within a day doubles the next one', function (): void {
	$throttle = authThrottleOver($this->cache);
	$lockouts = [];

	for ($round = 0; $round < 3; $round++) {
		for ($i = 0; $i < 3; $i++) {
			$lockout = $throttle->recordLoginFailure(authThrottleRequest(), 'auth|a@example.com');
		}
		$lockouts[] = $lockout;
	}

	expect($lockouts)->toBe([420, 840, 1680]);
});

test('lockouts stop at a day', function (): void {
	$throttle = authThrottleOver($this->cache, ['maxAttempts' => 1, 'deniedTimeout' => 1000]);

	expect($throttle->recordLoginFailure(authThrottleRequest(), 'auth|a@example.com'))->toBe(60000);
	expect($throttle->recordLoginFailure(authThrottleRequest(), 'auth|a@example.com'))->toBe(86400);
});

test('a good login clears the client failures and strikes', function (): void {
	$throttle = authThrottleOver($this->cache);

	$throttle->recordLoginFailure(authThrottleRequest(), 'auth|a@example.com');
	$throttle->recordLoginFailure(authThrottleRequest(), 'auth|a@example.com');
	$throttle->recordLoginSuccess(authThrottleRequest(), 'auth|a@example.com');

	expect($throttle->recordLoginFailure(authThrottleRequest(), 'auth|a@example.com'))->toBe(0)
		->and($throttle->recordLoginFailure(authThrottleRequest(), 'auth|a@example.com'))->toBe(0);
});

test('the form limit allows the limit, then refuses, per client', function (): void {
	$throttle = authThrottleOver($this->cache, ['mailRatePerIp' => 3]);
	$results  = [];

	for ($i = 0; $i < 4; $i++) {
		$results[] = $throttle->allowMail('forgot', authThrottleRequest());
	}

	expect($results)->toBe([true, true, true, false])
		->and($throttle->allowMail('forgot', authThrottleRequest('198.51.100.9')))->toBeTrue()
		->and($throttle->allowMail('resend', authThrottleRequest()))->toBeTrue();
});

test('a form limit of 0 turns the limit off', function (): void {
	$throttle = authThrottleOver($this->cache, ['registerRatePerIp' => 0]);

	for ($i = 0; $i < 30; $i++) {
		expect($throttle->allowRegister(authThrottleRequest()))->toBeTrue();
	}
});

test('an unreachable cache lets every request through', function (): void {
	$broken = new class extends CacheManager {
		public function __construct()
		{
		}

		public function getOperationalData(string $key): mixed
		{
			return null;
		}

		public function storeData(string $key, mixed $data, int $ttl = self::DEFAULT_TTL): bool
		{
			return false;
		}

		public function clearData(string $key): bool
		{
			return false;
		}
	};
	$throttle = authThrottleOver($broken, ['mailRatePerIp' => 1]);

	for ($i = 0; $i < 5; $i++) {
		$throttle->recordLoginFailure(authThrottleRequest(), 'auth|a@example.com');
	}

	expect($throttle->loginLockedFor(authThrottleRequest()))->toBe(0)
		->and($throttle->allowMail('forgot', authThrottleRequest()))->toBeTrue()
		->and($throttle->allowMail('forgot', authThrottleRequest()))->toBeTrue();
});

test('download password failures lock the client out of downloads, not login', function (): void {
	$throttle = authThrottleOver($this->cache, ['downloadMaxAttempts' => 2]);

	expect($throttle->recordDownloadFailure(authThrottleRequest()))->toBe(0)
		->and($throttle->recordDownloadFailure(authThrottleRequest()))->toBe(420)
		->and($throttle->downloadLockedFor(authThrottleRequest()))->toBeGreaterThan(400)
		->and($throttle->loginLockedFor(authThrottleRequest()))->toBe(0)
		->and($throttle->downloadLockedFor(authThrottleRequest('198.51.100.9')))->toBe(0);
});

test('a right download password clears the failures', function (): void {
	$throttle = authThrottleOver($this->cache, ['downloadMaxAttempts' => 2]);

	$throttle->recordDownloadFailure(authThrottleRequest());
	$throttle->recordDownloadSuccess(authThrottleRequest());

	expect($throttle->recordDownloadFailure(authThrottleRequest()))->toBe(0);
});
