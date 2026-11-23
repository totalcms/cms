<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Commerce;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Security\RateLimit\PublicRateLimiter;

/**
 * The limit on provider webhooks: 120 a minute from one address, named
 * `commerce-webhook` so an operator can change it in `rateLimits`. Every
 * request is checked for a signature, which costs a hash; the limit keeps
 * that from being worth anyone's while.
 */
final readonly class WebhookRateLimitMiddleware implements MiddlewareInterface
{
	public const NAME = 'commerce-webhook';

	private const MAX            = 120;
	private const WINDOW_SECONDS = 60;

	public function __construct(private PublicRateLimiter $limiter)
	{
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		return $this->limiter->middleware(self::NAME, self::MAX, self::WINDOW_SECONDS)->process($request, $handler);
	}
}
