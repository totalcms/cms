<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Commerce;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Security\RateLimit\PublicRateLimiter;

/**
 * The limit on starting a checkout: 10 a minute from one address, named
 * `commerce-checkout` so an operator can change it in `rateLimits`. Each
 * start makes an order and calls the payment provider, so it is held much
 * tighter than the cart.
 */
final readonly class CheckoutRateLimitMiddleware implements MiddlewareInterface
{
	public const NAME = 'commerce-checkout';

	private const MAX            = 10;
	private const WINDOW_SECONDS = 60;

	public function __construct(private PublicRateLimiter $limiter)
	{
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		return $this->limiter->middleware(self::NAME, self::MAX, self::WINDOW_SECONDS)->process($request, $handler);
	}
}
