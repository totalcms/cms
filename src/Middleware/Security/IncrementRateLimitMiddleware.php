<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use TotalCMS\Domain\Security\RateLimit\RateLimiter;
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
 * Counting is `RateLimiter`'s, which fails open when the cache is unreachable.
 */
readonly class IncrementRateLimitMiddleware implements MiddlewareInterface
{
	public const LIMIT_PER_MINUTE = 60;

	private const WINDOW = 60;

	public function __construct(
		private RateLimiter $limiter,
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

		$result = $this->limiter->attempt('increment', $this->clientIpResolver->resolve($request), self::LIMIT_PER_MINUTE, self::WINDOW);

		if (!$result->allowed) {
			return $this->renderer->json(
				(new Response())->withStatus(429)->withHeader('Retry-After', (string)$result->retryAfter),
				['error' => ['message' => 'Too many anonymous counter updates from this IP. Slow down.']],
			);
		}

		$response = $handler->handle($request);

		return $response
			->withHeader('X-RateLimit-Limit', (string)self::LIMIT_PER_MINUTE)
			->withHeader('X-RateLimit-Remaining', (string)$result->remaining)
			->withHeader('X-RateLimit-Window', (string)self::WINDOW);
	}
}
