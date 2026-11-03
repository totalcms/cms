<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use TotalCMS\Domain\ApiKey\Service\ApiKeyAuthenticator;
use TotalCMS\Domain\Security\RateLimit\RateLimiter;
use TotalCMS\Domain\Security\Request\ClientIpResolver;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

/**
 * Per-IP rate limit for anonymous MCP requests (no API key).
 *
 * **Scope.** Only anonymous callers are throttled — admin API key requests
 * skip the check entirely. Rationale: admin keys are already gated by
 * possession of a credential; anonymous access is the abuse surface (DDoS,
 * scraping, exhaustive enumeration of public collections). Per-key rate
 * limits will land in Phase 4 alongside OAuth.
 *
 * **Storage.** `RateLimiter` counts through `CacheManager`: accurate across
 * workers on Redis; on APCu alone the effective limit is
 * `publicIpPerMinute × worker_count`.
 *
 * **Config:** `mcp.publicIpPerMinute` — requests per IP per 60s window.
 * Default 60 (1 req/sec). When unset or zero, the limiter is effectively
 * disabled (any count below threshold is allowed).
 *
 * **Fail-open posture.** If every cache backend is unreachable, requests
 * pass. A misconfigured server doesn't lock out legitimate traffic.
 */
readonly class McpRateLimitMiddleware implements MiddlewareInterface
{
	private const WINDOW = 60;

	public function __construct(
		private RateLimiter $limiter,
		private ApiKeyAuthenticator $apiKeyAuthenticator,
		private JsonRenderer $renderer,
		private Config $config,
		private ClientIpResolver $clientIpResolver,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		// Admin (API-key-bearing) callers bypass the limit. We don't validate
		// the key here — McpAuth handles auth/scope downstream. This is purely
		// a routing decision: "does this look like an anonymous request?"
		if ($this->apiKeyAuthenticator->hasApiKeyHeader($request)) {
			return $handler->handle($request);
		}

		$limit = (int)($this->config->mcp['publicIpPerMinute'] ?? 60);
		if ($limit <= 0) {
			// Limiter effectively disabled.
			return $handler->handle($request);
		}

		$result = $this->limiter->attempt('mcp', $this->clientIpResolver->resolve($request), $limit, self::WINDOW);

		if (!$result->allowed) {
			return $this->tooManyRequests($limit, $result->retryAfter);
		}

		$response = $handler->handle($request);

		return $response
			->withHeader('X-RateLimit-Limit', (string)$limit)
			->withHeader('X-RateLimit-Remaining', (string)$result->remaining)
			->withHeader('X-RateLimit-Window', (string)self::WINDOW);
	}

	private function tooManyRequests(int $limit, int $retryAfter): ResponseInterface
	{
		return $this->renderer->json(
			(new Response())->withStatus(429),
			[
				'error' => [
					'message'    => 'Too many anonymous MCP requests from this IP. Slow down or use an API key.',
					'limit'      => $limit,
					'window_s'   => self::WINDOW,
				],
			],
		)
			->withHeader('Retry-After', (string)$retryAfter)
			->withHeader('X-RateLimit-Limit', (string)$limit)
			->withHeader('X-RateLimit-Window', (string)self::WINDOW);
	}
}
