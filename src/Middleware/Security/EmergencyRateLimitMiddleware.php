<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use TotalCMS\Domain\Security\RateLimit\RateLimiter;
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
 * Counting is `RateLimiter`'s, shared with every other rate limit.
 */
readonly class EmergencyRateLimitMiddleware implements MiddlewareInterface
{
	private const WINDOW    = 900; // 15 minutes
	private const LIMIT     = 1;

	public function __construct(
		private RateLimiter $limiter,
		private JsonRenderer $renderer,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		// One subject for the whole site: the limit is global, not per IP.
		$result = $this->limiter->attempt('emergency', '', self::LIMIT, self::WINDOW);

		if (!$result->allowed) {
			return $this->tooManyRequests($result->retryAfter);
		}

		return $handler->handle($request);
	}

	private function tooManyRequests(int $retryAfter): ResponseInterface
	{
		return $this->renderer->json(
			(new Response())->withStatus(429),
			[
				'error'    => 'Emergency cache clear was used recently. Wait 15 minutes before calling again.',
				'limit'    => self::LIMIT,
				'window_s' => self::WINDOW,
			],
		)
			->withHeader('Retry-After', (string)$retryAfter)
			->withHeader('X-RateLimit-Limit', (string)self::LIMIT)
			->withHeader('X-RateLimit-Window', (string)self::WINDOW);
	}
}
