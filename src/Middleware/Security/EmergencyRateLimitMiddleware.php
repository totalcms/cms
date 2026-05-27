<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Global rate limit on the unauthenticated `/emergency/cache/*` endpoints.
 *
 * **Why the endpoint is unauthenticated.** Emergency cache clear exists for
 * the self-service case where the admin UI itself can't load (license cache
 * corruption, etc.). Putting auth in front of "the thing you call when auth
 * is broken" defeats its purpose.
 *
 * **Why the rate limit is global, not per-IP.** A per-IP limit doesn't stop
 * the actual threat — distributed abuse rotating IPs to keep caches cold.
 * The legitimate use case is a single operator calling the endpoint once;
 * they don't need a per-IP allowance, and an IP-rotating attacker isn't
 * stopped by one. A single shared counter (1 call site-wide per 15 minutes)
 * lines up with the real-world workflow and kills the abuse vector outright.
 *
 * Counter routes through `CacheManager` (same pattern as
 * `McpRateLimitMiddleware` and the mailer's `RateLimitMiddleware`) — Redis
 * for accurate cross-worker accounting when available, with graceful
 * fallback through APCu / Memcached / filesystem.
 */
readonly class EmergencyRateLimitMiddleware implements MiddlewareInterface
{
	private const CACHE_KEY = 'emergency_rl_global';
	private const WINDOW    = 900; // 15 minutes
	private const LIMIT     = 1;

	public function __construct(
		private CacheManager $cache,
		private JsonRenderer $renderer,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$count = $this->getCount();

		if ($count >= self::LIMIT) {
			return $this->tooManyRequests();
		}

		$this->incrementCount();

		return $handler->handle($request);
	}

	private function getCount(): int
	{
		$count = $this->cache->getData(self::CACHE_KEY);

		return is_int($count) ? $count : 0;
	}

	private function incrementCount(): void
	{
		$this->cache->storeData(self::CACHE_KEY, $this->getCount() + 1, self::WINDOW);
	}

	private function tooManyRequests(): ResponseInterface
	{
		return $this->renderer->json(
			(new \Slim\Psr7\Response())->withStatus(429),
			[
				'error'    => 'Emergency cache clear was used recently. Wait 15 minutes before calling again.',
				'limit'    => self::LIMIT,
				'window_s' => self::WINDOW,
			],
		)
			->withHeader('Retry-After', (string)self::WINDOW)
			->withHeader('X-RateLimit-Limit', (string)self::LIMIT)
			->withHeader('X-RateLimit-Window', (string)self::WINDOW);
	}
}
