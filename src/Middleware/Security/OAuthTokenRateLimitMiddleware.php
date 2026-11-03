<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\Security\RateLimit\RateLimiter;
use TotalCMS\Domain\Security\Request\ClientIpResolver;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

/**
 * Per-IP rate limit for the OAuth /token and /register endpoints.
 *
 * Token endpoint: defaults to 60 requests/minute. Protects against
 * brute-force code-exchange attempts and runaway refresh-token loops.
 *
 * Register endpoint (RFC 7591 dynamic registration): defaults to
 * 60 requests/hour. Dynamic registration is the abuse vector for
 * client-record flooding — an unauthenticated endpoint that creates
 * persistent state on the server. Not tighter than that because hosted
 * AI clients (claude.ai, ChatGPT) register from their provider's shared
 * backend IPs and re-register on every connect attempt — at 10/hour a
 * few retries locked a customer's Claude connector out for the hour.
 *
 * Counting is `RateLimiter`'s: multi-worker accurate only on shared backends
 * (Redis); APCu-only deployments see per-worker counters.
 *
 * Applied uniformly to all callers — admin requests aren't exempt
 * because admins shouldn't pummel the token endpoint either.
 */
readonly class OAuthTokenRateLimitMiddleware implements MiddlewareInterface
{
	public function __construct(
		private RateLimiter $limiter,
		private JsonRenderer $renderer,
		private Config $config,
		private OAuthActivityLogger $activityLogger,
		private ClientIpResolver $clientIpResolver,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$path = $request->getUri()->getPath();

		if (str_ends_with($path, '/oauth/token')) {
			$limit  = (int)($this->config->oauth['tokenEndpointLimit'] ?? 60);
			$window = (int)($this->config->oauth['tokenEndpointWindow'] ?? 60);
			$name   = 'oauth-token';
		} elseif (str_ends_with($path, '/oauth/register')) {
			$limit  = (int)($this->config->oauth['dynamicRegistrationLimit'] ?? 60);
			$window = (int)($this->config->oauth['dynamicRegistrationWindow'] ?? 3600);
			$name   = 'oauth-register';
		} else {
			return $handler->handle($request);
		}

		if ($limit <= 0) {
			// Limiter effectively disabled for this endpoint.
			return $handler->handle($request);
		}

		$ip     = $this->clientIpResolver->resolve($request);
		$result = $this->limiter->attempt($name, $ip, $limit, $window);

		if (!$result->allowed) {
			$this->activityLogger->rateLimitHit($path, $ip);

			return $this->tooManyRequests($limit, $window, $result->retryAfter);
		}

		$response = $handler->handle($request);

		return $response
			->withHeader('X-RateLimit-Limit', (string)$limit)
			->withHeader('X-RateLimit-Remaining', (string)$result->remaining)
			->withHeader('X-RateLimit-Window', (string)$window);
	}

	private function tooManyRequests(int $limit, int $window, int $retryAfter): ResponseInterface
	{
		return $this->renderer->json(
			(new Response())->withStatus(429),
			[
				'error'             => 'too_many_requests',
				'error_description' => 'Rate limit exceeded for this OAuth endpoint.',
				'limit'             => $limit,
				'window_s'          => $window,
			],
		)
			->withHeader('Retry-After', (string)$retryAfter)
			->withHeader('X-RateLimit-Limit', (string)$limit)
			->withHeader('X-RateLimit-Window', (string)$window);
	}
}
