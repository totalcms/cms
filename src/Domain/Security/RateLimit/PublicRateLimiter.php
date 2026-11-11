<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\RateLimit;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Security\Request\ClientIpResolver;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Middleware\Security\PublicRateLimitMiddleware;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

/**
 * Named per-IP limits for public routes (anonymous comments, checkout,
 * webhooks, extension endpoints).
 *
 * The caller names the limit and supplies its default; an operator overrides
 * it with `$settings['rateLimits'][<name>] = ['max' => int, 'window' => seconds]`,
 * and `max` 0 turns it off. Core routes attach one with `middleware()`.
 * Extension routes cannot carry middleware, so their handlers call `hit()`:
 *
 *     if ($context->get(PublicRateLimiter::class)->hit('acme-chat', $request, 30, 3600)) {
 *         return $response->withStatus(429);
 *     }
 */
class PublicRateLimiter
{
	public function __construct(
		private readonly RateLimiter $limiter,
		private readonly Config $config,
		private readonly ClientIpResolver $clientIpResolver,
		private readonly JsonRenderer $renderer,
		private readonly TranslationService $translator,
	) {
	}

	/**
	 * Count one request by this client against the limit `$name`. True when
	 * the client is over the limit and the request should be refused.
	 */
	public function hit(string $name, ServerRequestInterface $request, int $max, int $windowSeconds): bool
	{
		return !$this->attempt($name, $request, $max, $windowSeconds)->allowed;
	}

	/**
	 * As `hit()`, with the full result for callers that set rate-limit headers.
	 */
	public function attempt(string $name, ServerRequestInterface $request, int $max, int $windowSeconds): RateLimitResult
	{
		$name     = $this->safeName($name);
		$override = $this->config->rateLimits[$name] ?? null;

		if (is_array($override)) {
			$max           = isset($override['max']) ? (int)$override['max'] : $max;
			$windowSeconds = isset($override['window']) ? (int)$override['window'] : $windowSeconds;
		}

		return $this->limiter->attempt('public-' . $name, $this->clientIpResolver->resolve($request), $max, $windowSeconds);
	}

	/**
	 * Middleware enforcing the limit `$name` on a core route group.
	 */
	public function middleware(string $name, int $max, int $windowSeconds): PublicRateLimitMiddleware
	{
		return new PublicRateLimitMiddleware($this, $this->renderer, $name, $max, $windowSeconds, $this->translator);
	}

	/**
	 * Names go into cache keys and settings, so keep them to letters, digits,
	 * `-` and `_`. `acme/chat send` becomes `acme-chat-send`.
	 */
	private function safeName(string $name): string
	{
		return (string)preg_replace('/[^A-Za-z0-9_-]/', '-', $name);
	}
}
