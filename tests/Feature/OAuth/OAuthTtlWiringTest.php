<?php

declare(strict_types=1);

use TotalCMS\Domain\OAuth\Repository\OAuthReplayDetector;
use TotalCMS\Domain\OAuth\Repository\OAuthRevocationList;
use TotalCMS\Support\Config;

// A revoked jti must stay in the revocation list for as long as its token can
// live — with the list's TTL pinned at 1 hour, a revoked 1-day token became
// valid again after an hour. Same rule for rotated refresh-token hashes.

beforeEach(function (): void {
	$this->setUpApp(bootstrap());
});

it('sizes the revocation and replay caches from the configured lifetimes', function (): void {
	$container     = $this->app->getContainer();
	$config        = $container->get(Config::class);
	$config->oauth = array_merge($config->oauth, [
		'accessTokenTtl'  => 'P1D',
		'refreshTokenTtl' => 'P90D',
	]);

	$revocation = $container->get(OAuthRevocationList::class);
	$replay     = $container->get(OAuthReplayDetector::class);

	expect((new ReflectionProperty($revocation, 'accessTokenTtlSeconds'))->getValue($revocation))->toBe(86400);
	expect((new ReflectionProperty($replay, 'refreshTokenTtlSeconds'))->getValue($replay))->toBe(90 * 86400);
});
