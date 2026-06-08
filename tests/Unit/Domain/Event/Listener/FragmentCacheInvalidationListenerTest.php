<?php

declare(strict_types=1);

use Odan\Session\SessionInterface;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Cache\FragmentCache;
use TotalCMS\Domain\Event\Listener\FragmentCacheInvalidationListener;
use TotalCMS\Domain\Event\Payload\ObjectEventPayload;

test('listener bumps the affected collection tag version on a content change', function (): void {
	$stored = [];

	$cache = test()->createMock(CacheManager::class);
	$cache->method('getOperationalData')->willReturnCallback(function (string $k) use (&$stored) {
		return $stored[$k] ?? null;
	});
	$cache->method('storeData')->willReturnCallback(function (string $k, $v) use (&$stored): bool {
		$stored[$k] = $v;

		return true;
	});

	$fragmentCache = new FragmentCache($cache, test()->createMock(SessionInterface::class));
	$listener = new FragmentCacheInvalidationListener($fragmentCache);

	$listener->onObjectChanged((new ObjectEventPayload('blog', 'post-1'))->toArray());
	expect($stored['fragver:blog'] ?? null)->toBe(1);

	$listener->onObjectChanged((new ObjectEventPayload('blog', 'post-2'))->toArray());
	expect($stored['fragver:blog'] ?? null)->toBe(2);
});
