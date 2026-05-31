<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Extension\Data\ExtensionState;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\EnvironmentResolver;
use TotalCMS\Domain\Extension\Service\ExtensionGuard;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Support\Config;

function guardEnv(string $env, bool $preview = false): EnvironmentResolver
{
	$config      = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->env = $env;
	return new EnvironmentResolver($config, $preview);
}

/**
 * Stateful CacheManager double. Backs getData()/storeData() with an in-memory
 * array so the rolling failure counter can be pre-seeded and inspected.
 *
 * Both callbacks capture $store by reference so reads always see the latest
 * writes — this makes the mock a true round-tripping cache for counter logic.
 *
 * @param array<string,mixed> $store passed by reference so tests can pre-seed and inspect
 */
function guardCache(array &$store): CacheManager
{
	$cache = test()->createMock(CacheManager::class);

	$cache->method('getData')->willReturnCallback(
		function (string $key) use (&$store): mixed {
			return $store[$key] ?? null;
		}
	);
	$cache->method('storeData')->willReturnCallback(
		function (string $key, mixed $data, int $ttl = 0) use (&$store): bool {
			$store[$key] = $data;
			return true;
		}
	);

	return $cache;
}

/**
 * Build a real ExtensionStateRepository backed by an in-memory storage map.
 *
 * ExtensionStateRepository is final (cannot be doubled), so we drive the real
 * thing through a stateful StorageFilesystemAdapter mock. Pre-seed states by
 * passing them in; inspect saved states by re-reading via getState().
 *
 * @param array<string,ExtensionState> $seed initial states keyed by extension id
 */
function guardStateRepo(array $seed = []): ExtensionStateRepository
{
	$files = [];
	if ($seed !== []) {
		$payload = [];
		foreach ($seed as $id => $state) {
			$payload[$id] = $state->toArray();
		}
		$files['.system/extensions.json'] = (string)json_encode($payload);
	}

	$storage = test()->createMock(StorageFilesystemAdapter::class);

	$storage->method('fileExists')->willReturnCallback(
		fn (string $location): bool => isset($files[$location])
	);
	$storage->method('read')->willReturnCallback(
		fn (string $location): string => $files[$location] ?? ''
	);
	$storage->method('write')->willReturnCallback(
		function (string $location, string $contents) use (&$files): bool {
			$files[$location] = $contents;
			return true;
		}
	);
	$storage->method('move')->willReturnCallback(
		function (string $old, string $new) use (&$files): bool {
			$files[$new] = $files[$old] ?? '';
			unset($files[$old]);
			return true;
		}
	);

	return new ExtensionStateRepository($storage);
}

describe('ExtensionGuard', function () {
	test('returns the callable result on success', function () {
		$store = [];
		$guard = new ExtensionGuard(guardEnv('prod'), guardCache($store), guardStateRepo(), new NullLogger());

		expect($guard->run('acme/widget', 'twig:fn', fn () => 42, fallback: null))->toBe(42);
	});

	test('catches a throw and returns the fallback', function () {
		$store = [];
		$guard = new ExtensionGuard(guardEnv('prod'), guardCache($store), guardStateRepo(), new NullLogger());

		$result = $guard->run('acme/widget', 'twig:fn', fn () => throw new RuntimeException('boom'), fallback: 'safe');

		expect($result)->toBe('safe');
	});

	test('counts at most one failure per extension per request', function () {
		$store = [];
		$guard = new ExtensionGuard(guardEnv('prod'), guardCache($store), guardStateRepo(), new NullLogger());

		$guard->run('acme/widget', 'twig:fn', fn () => throw new RuntimeException('one'), fallback: null);
		$guard->run('acme/widget', 'twig:fn', fn () => throw new RuntimeException('two'), fallback: null);

		// Two throws, same request + extension => counter incremented exactly once.
		expect($store['extguard:fail:acme/widget'])->toBe(1);
	});

	test('quarantines after threshold on prod', function () {
		$store = ['extguard:fail:acme/widget' => 4]; // one more failure reaches threshold of 5
		$repo  = guardStateRepo(['acme/widget' => new ExtensionState(enabled: true)]);
		$guard = new ExtensionGuard(guardEnv('prod'), guardCache($store), $repo, new NullLogger());

		$guard->run('acme/widget', 'twig:fn', fn () => throw new RuntimeException('final straw'), fallback: null);

		$state = $repo->getState('acme/widget');
		expect($state)->not->toBeNull()
			->and($state->isQuarantined())->toBeTrue()
			->and($state->quarantine['failureCount'])->toBe(5)
			->and($state->quarantine['lastError'])->toBe('final straw');
	});

	test('does not quarantine below threshold', function () {
		$store = ['extguard:fail:acme/widget' => 1];
		$repo  = guardStateRepo(['acme/widget' => new ExtensionState(enabled: true)]);
		$guard = new ExtensionGuard(guardEnv('prod'), guardCache($store), $repo, new NullLogger());

		$guard->run('acme/widget', 'twig:fn', fn () => throw new RuntimeException('nope'), fallback: null);

		expect($repo->getState('acme/widget')->isQuarantined())->toBeFalse();
	});

	test('never quarantines outside prod', function () {
		$store = ['extguard:fail:acme/widget' => 99]; // far above threshold
		$repo  = guardStateRepo(['acme/widget' => new ExtensionState(enabled: true)]);
		$guard = new ExtensionGuard(guardEnv('dev'), guardCache($store), $repo, new NullLogger());

		$guard->run('acme/widget', 'twig:fn', fn () => throw new RuntimeException('crash'), fallback: null);

		expect($repo->getState('acme/widget')->isQuarantined())->toBeFalse();
	});
});
