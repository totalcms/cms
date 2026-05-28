<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\ApiKey\Service\ApiKeyAuthenticator;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

/**
 * Per-IP rate limit for anonymous MCP requests (no API key).
 *
 * **Scope.** Only anonymous callers are throttled — admin API key requests
 * skip the check entirely. Rationale: admin keys are already gated by
 * possession of a credential; anonymous access is the abuse surface (DDoS,
 * scraping, exhaustive enumeration of public collections). Per-key rate
 * limits will land in Phase 4 alongside OAuth.
 *
 * **Storage.** Routes through T3's `CacheManager` so the counter goes to
 * whichever backend the site is configured for — Redis is the production
 * default (cross-worker accurate accounting), with graceful fallback to
 * APCu, Memcached, or the filesystem. A multi-worker php-fpm deployment
 * on Redis sees an accurate global counter; on APCu-only the counter is
 * per-worker so the effective limit is `publicIpPerMinute × worker_count`
 * — document that for operators if they're stress-testing.
 *
 * **Config:** `mcp.publicIpPerMinute` — requests per IP per 60s window.
 * Default 60 (1 req/sec). When unset or zero, the limiter is effectively
 * disabled (any count below threshold is allowed).
 *
 * **Fail-open posture.** CacheManager itself falls back through backends; if
 * every backend is unreachable the counter reads zero and requests pass
 * through. A misconfigured server doesn't lock out legitimate traffic.
 */
readonly class McpRateLimitMiddleware implements MiddlewareInterface
{
	private const CACHE_PREFIX = 'mcp_rl_';
	private const WINDOW       = 60;

	public function __construct(
		private CacheManager $cache,
		private ApiKeyAuthenticator $apiKeyAuthenticator,
		private JsonRenderer $renderer,
		private Config $config,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		// Admin (API-key-bearing) callers bypass the limit. We don't validate
		// the key here — McpAuth handles auth/scope downstream. This is purely
		// a routing decision: "does this look like an anonymous request?"
		if ($this->apiKeyAuthenticator->hasApiKeyHeader($request)) {
			return $handler->handle($request);
		}

		$limit = (int)($this->config->mcp['publicIpPerMinute'] ?? 60);
		if ($limit <= 0) {
			// Limiter effectively disabled.
			return $handler->handle($request);
		}

		$ip    = $this->getClientIp($request);
		$key   = self::CACHE_PREFIX . md5($ip);
		$count = $this->getCount($key);

		if ($count >= $limit) {
			return $this->tooManyRequests($limit);
		}

		$this->incrementCount($key);

		$response = $handler->handle($request);

		return $response
			->withHeader('X-RateLimit-Limit', (string)$limit)
			->withHeader('X-RateLimit-Remaining', (string)max(0, $limit - $count - 1))
			->withHeader('X-RateLimit-Window', (string)self::WINDOW);
	}

	private function getClientIp(ServerRequestInterface $request): string
	{
		// Trust proxy-injected client-IP headers in this order: Cloudflare, then
		// X-Forwarded-For first hop. Both can be spoofed in misconfigured
		// environments, so production operators MUST front the server with a
		// trusted reverse proxy if the rate limit is load-bearing for security.
		if ($request->hasHeader('CF-Connecting-IP')) {
			return $request->getHeaderLine('CF-Connecting-IP');
		}
		if ($request->hasHeader('X-Forwarded-For')) {
			$first = explode(',', $request->getHeaderLine('X-Forwarded-For'))[0];

			return trim($first);
		}

		return $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
	}

	private function getCount(string $key): int
	{
		// CacheManager returns null when the key is absent or every backend
		// is unreachable — both collapse to "zero" so the limiter fails
		// open rather than locking out legitimate traffic on a broken cache.
		$count = $this->cache->getData($key);

		return is_int($count) ? $count : 0;
	}

	private function incrementCount(string $key): void
	{
		$this->cache->storeData($key, $this->getCount($key) + 1, self::WINDOW);
	}

	private function tooManyRequests(int $limit): ResponseInterface
	{
		return $this->renderer->json(
			(new \Slim\Psr7\Response())->withStatus(429),
			[
				'error' => [
					'message'    => 'Too many anonymous MCP requests from this IP. Slow down or use an API key.',
					'limit'      => $limit,
					'window_s'   => self::WINDOW,
				],
			],
		)
			->withHeader('Retry-After', (string)self::WINDOW)
			->withHeader('X-RateLimit-Limit', (string)$limit)
			->withHeader('X-RateLimit-Window', (string)self::WINDOW);
	}
}
