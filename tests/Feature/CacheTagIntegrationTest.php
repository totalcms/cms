<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use TotalCMS\Domain\Cache\FragmentCache;

/**
 * Resolves a real FragmentCache (real CacheManager backends — APCu in the test
 * env, filesystem fallback otherwise) from the application container, so this
 * exercises the genuine cache round-trip and generational invalidation rather
 * than a mocked cache.
 */
function fragmentCacheFromContainer(): FragmentCache
{
	$builder = new ContainerBuilder();
	$builder->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');

	return $builder->build()->get(FragmentCache::class);
}

test('a tagged fragment is cached, served from cache, then re-rendered after the tag is bumped', function (): void {
	$fc = fragmentCacheFromContainer();

	$calls = 0;
	$body = function () use (&$calls): string {
		$calls++;

		return "render-{$calls}";
	};

	// Unique key so the fragment slot is isolated from other test runs; the
	// 'blogx' version counter may be shared in APCu but the assertions only
	// depend on relative behaviour (hit, then bust), not the absolute version.
	$key = 'integration:' . uniqid();

	expect($fc->render($key, 60, ['blogx'], false, $body))->toBe('render-1');
	expect($fc->render($key, 60, ['blogx'], false, $body))->toBe('render-1'); // served from cache
	expect($calls)->toBe(1);

	$fc->bumpTag('blogx');

	expect($fc->render($key, 60, ['blogx'], false, $body))->toBe('render-2'); // busted, re-rendered
	expect($calls)->toBe(2);
});
