<?php

declare(strict_types=1);

use Odan\Session\SessionInterface;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Cache\FragmentCache;
use TotalCMS\Domain\Session\SessionKeys;

/**
 * @param array<string,mixed> $store
 */
function makeFragmentCache(array &$store, bool $authed = false, bool $disabled = false): FragmentCache
{
	$cache = test()->createMock(CacheManager::class);
	$cache->method('isCacheDisabled')->willReturn($disabled);
	$cache->method('applyDomainPrefix')->willReturnCallback(fn (string $k): string => 'dom:' . $k);
	$cache->method('getData')->willReturnCallback(function (string $k) use (&$store) {
		return $store[$k] ?? null;
	});
	$cache->method('getOperationalData')->willReturnCallback(function (string $k) use (&$store) {
		return $store[$k] ?? null;
	});
	$cache->method('storeData')->willReturnCallback(function (string $k, $v) use (&$store): bool {
		$store[$k] = $v;

		return true;
	});

	$session = test()->createMock(SessionInterface::class);
	$session->method('get')->willReturnCallback(
		fn (string $k) => ($authed && $k === SessionKeys::AUTH_USER) ? 'user-1' : null
	);

	return new FragmentCache($cache, $session, 3600, true);
}

describe('FragmentCache', function (): void {
	test('renders body on first call and serves cached HTML on second', function (): void {
		$store = [];
		$fc    = makeFragmentCache($store);
		$calls = 0;
		$body  = function () use (&$calls): string {
			$calls++;

			return "<p>hi {$calls}</p>";
		};

		expect($fc->render('k', null, [], false, $body))->toBe('<p>hi 1</p>');
		expect($fc->render('k', null, [], false, $body))->toBe('<p>hi 1</p>');
		expect($calls)->toBe(1);
	});

	test('bypasses cache (always renders) for an authenticated request', function (): void {
		$store = [];
		$fc    = makeFragmentCache($store, authed: true);
		$calls = 0;
		$body  = function () use (&$calls): string {
			$calls++;

			return "n{$calls}";
		};

		expect($fc->render('k', null, [], false, $body))->toBe('n1');
		expect($fc->render('k', null, [], false, $body))->toBe('n2');
		expect($calls)->toBe(2);
	});

	test('shared=true caches even when authenticated', function (): void {
		$store = [];
		$fc    = makeFragmentCache($store, authed: true);
		$calls = 0;
		$body  = function () use (&$calls): string {
			$calls++;

			return "n{$calls}";
		};

		expect($fc->render('k', null, [], true, $body))->toBe('n1');
		expect($fc->render('k', null, [], true, $body))->toBe('n1');
		expect($calls)->toBe(1);
	});

	test('bumpTag changes the storage key so the old fragment is no longer served', function (): void {
		$store = [];
		$fc    = makeFragmentCache($store);
		$calls = 0;
		$body  = function () use (&$calls): string {
			$calls++;

			return "v{$calls}";
		};

		expect($fc->render('k', null, ['blog'], false, $body))->toBe('v1');
		expect($fc->render('k', null, ['blog'], false, $body))->toBe('v1'); // cached hit
		expect($calls)->toBe(1);

		$fc->bumpTag('blog');
		expect($fc->render('k', null, ['blog'], false, $body))->toBe('v2'); // busted
		expect($calls)->toBe(2);
	});

	test('empty key never caches', function (): void {
		$store = [];
		$fc    = makeFragmentCache($store);
		$calls = 0;
		$body  = function () use (&$calls): string {
			$calls++;

			return "x{$calls}";
		};

		$fc->render('', null, [], false, $body);
		$fc->render('', null, [], false, $body);
		expect($calls)->toBe(2);
	});

	test('disabled cache always renders live', function (): void {
		$store = [];
		$fc    = makeFragmentCache($store, disabled: true);
		$calls = 0;
		$body  = function () use (&$calls): string {
			$calls++;

			return "x{$calls}";
		};

		$fc->render('k', null, [], false, $body);
		$fc->render('k', null, [], false, $body);
		expect($calls)->toBe(2);
	});
});
