<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use TotalCMS\Domain\Security\RateLimit\RateLimiter;
use TotalCMS\Domain\Security\RateLimit\RateLimitResult;
use TotalCMS\Domain\Security\Request\ClientIpResolver;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

/**
 * Rate limiting middleware for mailer endpoints — per-IP + per-template caps.
 *
 * Counting is `RateLimiter`'s: fixed windows, cross-worker accurate on
 * Redis, fail-open when every cache backend is unreachable.
 */
readonly class RateLimitMiddleware implements MiddlewareInterface
{
	public function __construct(
		private RateLimiter $limiter,
		private JsonRenderer $renderer,
		private Config $config,
		private ClientIpResolver $clientIpResolver,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		// Get rate limit config (cast to int since JSON settings come as strings)
		$mailerConfig     = $this->config->mailer;
		$perIpLimit       = (int)($mailerConfig['ratePerIp'] ?? 10);
		$perTemplateLimit = (int)($mailerConfig['ratePerTemplate'] ?? 50);
		$window           = (int)($mailerConfig['rateWindow'] ?? 300); // 5 minutes

		$ip = $this->limiter->attempt('mailer-ip', $this->clientIpResolver->resolve($request), $perIpLimit, $window);
		if (!$ip->allowed) {
			return $this->createRateLimitResponse($ip, 'IP');
		}

		// Get template ID from request body
		$data     = (array)$request->getParsedBody();
		$mailerId = (string)($data['mailerId'] ?? '');
		if ($mailerId !== '') {
			$template = $this->limiter->attempt('mailer-template', $mailerId, $perTemplateLimit, $window);
			if (!$template->allowed) {
				return $this->createRateLimitResponse($template, 'Template');
			}
		}

		// Add rate limit headers
		$response = $handler->handle($request);

		return $response
			->withHeader('X-RateLimit-IP-Limit', (string)$perIpLimit)
			->withHeader('X-RateLimit-IP-Remaining', (string)$ip->remaining)
			->withHeader('X-RateLimit-Window', (string)$ip->window);
	}

	private function createRateLimitResponse(RateLimitResult $result, string $type): ResponseInterface
	{
		return $this->renderer->json(
			(new Response())->withStatus(429),
			[
				'success'     => false,
				'message'     => 'Rate limit exceeded',
				'error'       => "Too many requests from this {$type}. Please try again later.",
				'retry_after' => $result->retryAfter,
				'limit'       => $result->limit,
				'window'      => $result->window,
			]
		)
		->withHeader('Retry-After', (string)$result->retryAfter)
		->withHeader('X-RateLimit-Limit', (string)$result->limit)
		->withHeader('X-RateLimit-Window', (string)$result->window);
	}
}
