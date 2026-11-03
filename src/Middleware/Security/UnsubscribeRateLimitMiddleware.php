<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Security\RateLimit\PublicRateLimiter;

/**
 * The `unsubscribe` public limit on Bulk Send's unsubscribe page: 30
 * requests per IP per hour, tunable with `$settings['rateLimits']['unsubscribe']`.
 */
readonly class UnsubscribeRateLimitMiddleware implements MiddlewareInterface
{
	public const NAME   = 'unsubscribe';
	public const MAX    = 30;
	public const WINDOW = 3600;

	public function __construct(private PublicRateLimiter $limiter)
	{
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		return $this->limiter->middleware(self::NAME, self::MAX, self::WINDOW)->process($request, $handler);
	}
}
