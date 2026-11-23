<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Commerce;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Security\RateLimit\PublicRateLimiter;

/**
 * The limit on trying a discount code: 10 a minute from one address, named
 * `commerce-discount` so an operator can change it in `rateLimits`. Applying
 * a code is the one place a visitor can guess codes, and the answer is the
 * same for every wrong guess, so the limit is what makes guessing useless.
 */
final readonly class DiscountRateLimitMiddleware implements MiddlewareInterface
{
	public const NAME = 'commerce-discount';

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
