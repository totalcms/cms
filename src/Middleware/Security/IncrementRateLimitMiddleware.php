<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Security\Request\ClientIpResolver;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Per-IP limit on anonymous counter writes.
 *
 * A collection can open its number properties to the public through the
 * `increment` operation (likes, "was this helpful", download counts). That
 * is a soft signal by design, but a loop hammering it is still abuse, so
 * anonymous callers get a fixed budget per minute. Anyone the auth layer
 * identified — session, API key, OAuth — passes straight through.
 *
 * Same shape as McpRateLimitMiddleware: a cache-backed counter that fails
 * open when the cache is unreachable.
 */
readonly class IncrementRateLimitMiddleware implements MiddlewareInterface
{
	public const LIMIT_PER_MINUTE = 60;

	private const CACHE_PREFIX = 'inc_rl_';
	private const WINDOW       = 60;

	public function __construct(
		private CacheManager $cache,
		private JsonRenderer $renderer,
		private ClientIpResolver $clientIpResolver,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		// DualAuthMiddleware stamps `authMethod` for every identified caller.
		// No stamp means anonymous (a public-operation request, or auth off).
		if ((string)$request->getAttribute('authMethod', '') !== '') {
			return $handler->handle($request);
		}

		$key   = self::CACHE_PREFIX . md5($this->clientIpResolver->resolve($request));
		$count = $this->getCount($key);

		if ($count >= self::LIMIT_PER_MINUTE) {
			return $this->renderer->json(
				(new \Slim\Psr7\Response())->withStatus(429)->withHeader('Retry-After', (string)self::WINDOW),
				['error' => ['message' => 'Too many anonymous counter updates from this IP. Slow down.']],
			);
		}

		$this->cache->storeData($key, $count + 1, self::WINDOW);

		$response = $handler->handle($request);

		return $response
			->withHeader('X-RateLimit-Limit', (string)self::LIMIT_PER_MINUTE)
			->withHeader('X-RateLimit-Remaining', (string)max(0, self::LIMIT_PER_MINUTE - $count - 1))
			->withHeader('X-RateLimit-Window', (string)self::WINDOW);
	}

	private function getCount(string $key): int
	{
		$count = $this->cache->getData($key);

		return is_int($count) ? $count : 0;
	}
}
