<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Security\RateLimit\PublicRateLimiter;
use TotalCMS\Domain\Security\RateLimit\RateLimiter;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

/*
 * Named per-IP limits for public routes. Core routes attach one as
 * middleware; extension handlers call hit(), because extension routes
 * cannot carry middleware. An operator retunes or disables any of them with
 * `$settings['rateLimits'][<name>] = ['max' => …, 'window' => …]`.
 */

function publicLimiter(array $rateLimits = []): PublicRateLimiter
{
	$config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	(new ReflectionProperty($config, 'rateLimits'))->setValue($config, $rateLimits);

	return new PublicRateLimiter(new RateLimiter(rateLimitCache(), static fn (): int => 1_000_000), $config, testClientIpResolver(), new JsonRenderer());
}

function limiterRequest(string $ip = '203.0.113.5'): ServerRequestInterface
{
	return new ServerRequest('POST', '/ext/acme/chat/send', [], null, '1.1', ['REMOTE_ADDR' => $ip]);
}

function okHandler(): RequestHandlerInterface
{
	return new class implements RequestHandlerInterface {
		public function handle(ServerRequestInterface $request): ResponseInterface
		{
			return new Response(200);
		}
	};
}

test('hit() answers true once a caller is over the limit', function (): void {
	$limiter = publicLimiter();

	expect($limiter->hit('bk-chat', limiterRequest(), 2, 3600))->toBeFalse()
		->and($limiter->hit('bk-chat', limiterRequest(), 2, 3600))->toBeFalse()
		->and($limiter->hit('bk-chat', limiterRequest(), 2, 3600))->toBeTrue()
		->and($limiter->hit('bk-chat', limiterRequest('198.51.100.9'), 2, 3600))->toBeFalse('each IP has its own allowance');
});

test('limits with different names do not share a count', function (): void {
	$limiter = publicLimiter();
	$limiter->hit('ratings', limiterRequest(), 1, 60);

	expect($limiter->hit('ratings', limiterRequest(), 1, 60))->toBeTrue()
		->and($limiter->hit('comments', limiterRequest(), 1, 60))->toBeFalse();
});

test('settings override the limit the caller asks for', function (): void {
	$limiter = publicLimiter(['ratings' => ['max' => '1', 'window' => 600]]);

	$first  = $limiter->attempt('ratings', limiterRequest(), 50, 60);
	$second = $limiter->attempt('ratings', limiterRequest(), 50, 60);

	expect($first->allowed)->toBeTrue()
		->and($second->allowed)->toBeFalse()
		->and($second->limit)->toBe(1)
		->and($second->window)->toBe(600);
});

test('a max of zero in settings switches a limit off', function (): void {
	$limiter = publicLimiter(['ratings' => ['max' => 0]]);

	foreach (range(1, 5) as $_) {
		expect($limiter->hit('ratings', limiterRequest(), 1, 60))->toBeFalse();
	}
});

test('the middleware answers 429 with Retry-After once the limit is spent', function (): void {
	$middleware = publicLimiter()->middleware('commerce-checkout', 1, 60);

	$ok      = $middleware->process(limiterRequest(), okHandler());
	$blocked = $middleware->process(limiterRequest(), okHandler());

	expect($ok->getStatusCode())->toBe(200)
		->and($ok->getHeaderLine('X-RateLimit-Limit'))->toBe('1')
		->and($ok->getHeaderLine('X-RateLimit-Remaining'))->toBe('0')
		->and($blocked->getStatusCode())->toBe(429)
		->and($blocked->getHeaderLine('Retry-After'))->toBe('60')
		->and(json_decode((string)$blocked->getBody(), true)['error']['message'])->toBe('Too many requests. Try again in 60 seconds.');
});

test('names are kept to safe cache-key characters', function (): void {
	$limiter = publicLimiter();
	$limiter->hit('acme/chat send', limiterRequest(), 1, 60);

	expect($limiter->hit('acme-chat-send', limiterRequest(), 1, 60))->toBeTrue();
});
