<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use TotalCMS\Domain\Security\RateLimit\PublicRateLimiter;
use TotalCMS\Renderer\JsonRenderer;

/**
 * One named per-IP limit on a core route group. Built by
 * `PublicRateLimiter::middleware()`, which carries the name and default:
 *
 *     ->add($container->get(PublicRateLimiter::class)->middleware('ratings', 10, 3600))
 */
readonly class PublicRateLimitMiddleware implements MiddlewareInterface
{
	public function __construct(
		private PublicRateLimiter $limiter,
		private JsonRenderer $renderer,
		private string $name,
		private int $max,
		private int $windowSeconds,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$result = $this->limiter->attempt($this->name, $request, $this->max, $this->windowSeconds);

		if (!$result->allowed) {
			return $this->renderer->json(
				(new Response())->withStatus(429),
				['error' => ['message' => "Too many requests. Try again in {$result->retryAfter} seconds."]],
			)
				->withHeader('Retry-After', (string)$result->retryAfter)
				->withHeader('X-RateLimit-Limit', (string)$result->limit)
				->withHeader('X-RateLimit-Window', (string)$result->window);
		}

		$response = $handler->handle($request);

		if ($result->limit === 0) {
			return $response;
		}

		return $response
			->withHeader('X-RateLimit-Limit', (string)$result->limit)
			->withHeader('X-RateLimit-Remaining', (string)$result->remaining)
			->withHeader('X-RateLimit-Window', (string)$result->window);
	}
}
