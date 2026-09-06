<?php

declare(strict_types=1);

use function TotalCMS\Slim\Pest\post;

/**
 * Anonymous counter writes get 60 per minute per IP. The test environment
 * runs with auth disabled, so every call here is anonymous from the
 * limiter's point of view — which is exactly the case it guards.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$container = $this->app->getContainer();
	$container->get(TotalCMS\Domain\Collection\Service\CollectionFetcher::class)->fetchOrCreateReserved('number');
	$container->get(TotalCMS\Domain\Object\Service\ObjectSaver::class)->saveObject('number', ['id' => 'likes', 'number' => 0]);
});

it('increments and reports the remaining budget', function (): void {
	$response = post('/api/collections/number/likes/number/increment');

	$response->assertOk()->assertHeader('X-RateLimit-Limit', '60');
	expect((int)$response->getHeaderLine('X-RateLimit-Remaining'))->toBe(59);
});

it('returns 429 once the per-minute budget is spent', function (): void {
	$limit = TotalCMS\Middleware\Security\IncrementRateLimitMiddleware::LIMIT_PER_MINUTE;
	for ($i = 0; $i < $limit; $i++) {
		post('/api/collections/number/likes/number/increment')->assertOk();
	}

	$response = post('/api/collections/number/likes/number/increment');

	expect($response->getStatusCode())->toBe(429);
	expect($response->getHeaderLine('Retry-After'))->toBe('60');
});
