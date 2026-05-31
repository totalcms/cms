<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Extension\Service\EnvironmentResolver;
use TotalCMS\Domain\Extension\Service\ExtensionProfiler;
use TotalCMS\Support\Config;

function profEnv(string $env, bool $preview = false): EnvironmentResolver
{
	$config      = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->env = $env;

	return new EnvironmentResolver($config, $preview);
}

/**
 * Build a CacheManager double backed by a by-reference $store.
 * IMPORTANT: closures must capture $store by reference (function (...) use (&$store))
 * so writes round-trip. Arrow functions capture by value and break this.
 *
 * @return array{0:CacheManager,1:callable(string):mixed}
 */
function profCacheWithStore(): array
{
	$store = [];

	$mock = test()->createMock(CacheManager::class);
	$mock->method('getData')->willReturnCallback(
		function (string $key) use (&$store): mixed {
			return $store[$key] ?? null;
		}
	);
	$mock->method('storeData')->willReturnCallback(
		function (string $key, mixed $data, int $ttl = 7200) use (&$store): bool {
			$store[$key] = $data;

			return true;
		}
	);

	$read = function (string $key) use (&$store): mixed {
		return $store[$key] ?? null;
	};

	return [$mock, $read];
}

function profCache(): CacheManager
{
	[$cache] = profCacheWithStore();

	return $cache;
}

describe('ExtensionProfiler', function (): void {
	test('dev always profiles', function (): void {
		$p = new ExtensionProfiler(profEnv('dev'), profCache(), sampleRate: 0, logger: new NullLogger());
		expect($p->isProfiling())->toBeTrue();
	});
	test('prod with sampleRate 0 never profiles', function (): void {
		$p = new ExtensionProfiler(profEnv('prod'), profCache(), sampleRate: 0, logger: new NullLogger());
		expect($p->isProfiling())->toBeFalse();
	});
	test('prod with sampleRate 1 always profiles', function (): void {
		$p = new ExtensionProfiler(profEnv('prod'), profCache(), sampleRate: 1, logger: new NullLogger());
		expect($p->isProfiling())->toBeTrue();
	});
	test('time() accumulates per extension when profiling', function (): void {
		$p = new ExtensionProfiler(profEnv('dev'), profCache(), sampleRate: 1, logger: new NullLogger());
		$p->time('acme/widget', fn (): null => usleep(1000));
		expect($p->totalMicrosFor('acme/widget'))->toBeGreaterThan(0);
	});
	test('time() returns the callable result', function (): void {
		$p = new ExtensionProfiler(profEnv('dev'), profCache(), sampleRate: 1, logger: new NullLogger());
		expect($p->time('acme/widget', fn (): int => 7))->toBe(7);
	});
	test('time() does NOT accumulate when not profiling', function (): void {
		$p = new ExtensionProfiler(profEnv('prod'), profCache(), sampleRate: 0, logger: new NullLogger());
		$p->time('acme/widget', fn (): null => usleep(1000));
		expect($p->totalMicrosFor('acme/widget'))->toBe(0);
	});
	test('flush writes per-extension metrics to cache', function (): void {
		[$cache, $readStore] = profCacheWithStore();
		$p                   = new ExtensionProfiler(profEnv('dev'), $cache, sampleRate: 1, logger: new NullLogger());
		$p->time('acme/widget', fn (): null => usleep(1000));
		$p->flush();
		expect($readStore('extprof:acme/widget'))->not->toBeNull();
	});
	test('flush is a no-op when not profiling', function (): void {
		[$cache, $readStore] = profCacheWithStore();
		$p                   = new ExtensionProfiler(profEnv('prod'), $cache, sampleRate: 0, logger: new NullLogger());
		$p->time('acme/widget', fn (): null => usleep(1000));
		$p->flush();
		expect($readStore('extprof:acme/widget'))->toBeNull();
	});
});
