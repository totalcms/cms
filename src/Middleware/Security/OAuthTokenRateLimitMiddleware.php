<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

/**
 * Per-IP rate limit for the OAuth /token and /register endpoints.
 *
 * Token endpoint: defaults to 60 requests/minute. Protects against
 * brute-force code-exchange attempts and runaway refresh-token loops.
 *
 * Register endpoint (RFC 7591 dynamic registration): defaults to
 * 10 requests/hour. Dynamic registration is the abuse vector for
 * client-record flooding — an unauthenticated endpoint that creates
 * persistent state on the server. Tight throttle by design.
 *
 * Storage routes through CacheManager — APCu→Redis→Memcached→filesystem.
 * Multi-worker accurate only on shared backends (Redis); APCu-only
 * deployments see per-worker counters (effective limit × worker_count).
 *
 * Applied uniformly to all callers — admin requests aren't exempt
 * because admins shouldn't pummel the token endpoint either.
 */
readonly class OAuthTokenRateLimitMiddleware implements MiddlewareInterface
{
	private const PREFIX_TOKEN    = 'oauth_rl_token_';
	private const PREFIX_REGISTER = 'oauth_rl_register_';

	public function __construct(
		private CacheManager $cache,
		private JsonRenderer $renderer,
		private Config $config,
		private OAuthActivityLogger $activityLogger,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$path = $request->getUri()->getPath();

		if (str_ends_with($path, '/oauth/token')) {
			$limit  = (int)($this->config->oauth['tokenEndpointLimit'] ?? 60);
			$window = (int)($this->config->oauth['tokenEndpointWindow'] ?? 60);
			$prefix = self::PREFIX_TOKEN;
		} elseif (str_ends_with($path, '/oauth/register')) {
			$limit  = (int)($this->config->oauth['dynamicRegistrationLimit'] ?? 10);
			$window = (int)($this->config->oauth['dynamicRegistrationWindow'] ?? 3600);
			$prefix = self::PREFIX_REGISTER;
		} else {
			return $handler->handle($request);
		}

		if ($limit <= 0) {
			// Limiter effectively disabled for this endpoint.
			return $handler->handle($request);
		}

		$ip    = $this->getClientIp($request);
		$key   = $prefix . md5($ip);
		$count = $this->getCount($key);

		if ($count >= $limit) {
			$this->activityLogger->rateLimitHit($path, $ip);
			return $this->tooManyRequests($limit, $window);
		}

		$this->incrementCount($key, $window);

		$response = $handler->handle($request);

		return $response
			->withHeader('X-RateLimit-Limit', (string)$limit)
			->withHeader('X-RateLimit-Remaining', (string)max(0, $limit - $count - 1))
			->withHeader('X-RateLimit-Window', (string)$window);
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

	private function incrementCount(string $key, int $window): void
	{
		$this->cache->storeData($key, $this->getCount($key) + 1, $window);
	}

	private function tooManyRequests(int $limit, int $window): ResponseInterface
	{
		return $this->renderer->json(
			(new \Slim\Psr7\Response())->withStatus(429),
			[
				'error'             => 'too_many_requests',
				'error_description' => 'Rate limit exceeded for this OAuth endpoint.',
				'limit'             => $limit,
				'window_s'          => $window,
			],
		)
			->withHeader('Retry-After', (string)$window)
			->withHeader('X-RateLimit-Limit', (string)$limit)
			->withHeader('X-RateLimit-Window', (string)$window);
	}
}
