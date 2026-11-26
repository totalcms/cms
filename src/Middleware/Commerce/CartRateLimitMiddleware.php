<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Commerce;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Security\RateLimit\PublicRateLimiter;

/**
 * The cart routes' limit: 60 requests a minute from one address, named
 * `commerce-cart` so an operator can change it in `rateLimits`.
 *
 * A class of its own so the route file names it and the limiter is only
 * built for a request that reaches a cart route.
 */
final readonly class CartRateLimitMiddleware implements MiddlewareInterface
{
	public const NAME = 'commerce-cart';

	private const MAX            = 60;
	private const WINDOW_SECONDS = 60;

	public function __construct(private PublicRateLimiter $limiter)
	{
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		return $this->limiter->middleware(self::NAME, self::MAX, self::WINDOW_SECONDS)->process($request, $handler);
	}
}
