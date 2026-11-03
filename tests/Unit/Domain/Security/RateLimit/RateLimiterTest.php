<?php

declare(strict_types=1);

use TotalCMS\Domain\Security\RateLimit\RateLimiter;

/*
 * The one counter behind every rate limit. A window opens at the first
 * request and closes a fixed time later; requests inside it never stretch
 * it. Each limiter used to re-store its counter with a fresh TTL on every
 * request, so a client well under the rate was blocked once its total
 * reached the limit.
 */

beforeEach(function (): void {
	$this->now     = 1_000_000;
	$this->cache   = rateLimitCache();
	$this->limiter = new RateLimiter($this->cache, fn (): int => $this->now);
});

test('it allows up to the limit and blocks the next request', function (): void {
	$first  = $this->limiter->attempt('mcp', '1.2.3.4', 2, 60);
	$second = $this->limiter->attempt('mcp', '1.2.3.4', 2, 60);
	$third  = $this->limiter->attempt('mcp', '1.2.3.4', 2, 60);

	expect([$first->allowed, $first->remaining])->toBe([true, 1])
		->and([$second->allowed, $second->remaining])->toBe([true, 0])
		->and([$third->allowed, $third->remaining])->toBe([false, 0])
		->and($third->limit)->toBe(2)
		->and($third->window)->toBe(60);
});

test('a steady client under the rate is never blocked', function (): void {
	// One request every 30 s against 2 per minute. The old limiters
	// blocked this on the third request.
	for ($i = 0; $i < 10; $i++) {
		expect($this->limiter->attempt('mcp', '1.2.3.4', 2, 60)->allowed)->toBeTrue("request {$i}");
		$this->now += 30;
	}
});

test('requests inside a window do not stretch it', function (): void {
	$this->limiter->attempt('t', 'k', 1, 60);
	$this->now += 59;
	$blocked = $this->limiter->attempt('t', 'k', 1, 60);
	$this->now += 1;

	expect($blocked->allowed)->toBeFalse()
		->and($blocked->retryAfter)->toBe(1)
		->and($this->limiter->attempt('t', 'k', 1, 60)->allowed)->toBeTrue();
});

test('retry-after counts down to the end of the window', function (): void {
	$this->limiter->attempt('t', 'k', 1, 900);
	$this->now += 300;

	expect($this->limiter->attempt('t', 'k', 1, 900)->retryAfter)->toBe(600);
});

test('keys are counted separately', function (): void {
	$this->limiter->attempt('t', 'a', 1, 60);

	expect($this->limiter->attempt('t', 'a', 1, 60)->allowed)->toBeFalse()
		->and($this->limiter->attempt('t', 'b', 1, 60)->allowed)->toBeTrue();
});

test('a limit of zero or less disables it', function (): void {
	foreach (range(1, 5) as $_) {
		expect($this->limiter->attempt('t', 'k', 0, 60)->allowed)->toBeTrue();
	}
	expect($this->cache->store)->toBe([]);
});

test('it fails open when the cache is unreachable', function (): void {
	$limiter = new RateLimiter(rateLimitCache(false), fn (): int => $this->now);

	foreach (range(1, 5) as $_) {
		expect($limiter->attempt('t', 'k', 1, 60)->allowed)->toBeTrue();
	}
});

test('a corrupt counter starts a new window', function (): void {
	$this->limiter->attempt('t', 'k', 1, 60);
	foreach ($this->cache->store as $key => $_) {
		$this->cache->store[$key] = 'garbage';
	}

	expect($this->limiter->attempt('t', 'k', 1, 60)->allowed)->toBeTrue();
});

test('the cache key names the limit so one limit can be cleared by pattern', function (): void {
	$this->limiter->attempt('emergency', '', 1, 900);

	expect(array_keys($this->cache->store))->toBe(['rl_emergency_' . md5('')]);
});

test('it keeps counting in dev mode', function (): void {
	// getData() returns nothing in dev mode or with the cache disabled, which
	// used to switch every limiter off. The test cache's getData() always
	// returns null, so counting has to go through getOperationalData().
	$this->limiter->attempt('t', 'k', 1, 60);

	expect($this->cache->getData('rl_t_' . md5('k')))->toBeNull()
		->and($this->limiter->attempt('t', 'k', 1, 60)->allowed)->toBeFalse();
});
