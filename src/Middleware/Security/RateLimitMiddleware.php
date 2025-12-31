<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Cache\Service\APCuService;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

/**
 * Rate limiting middleware using APCu for storage.
 *
 * Protects email endpoints against abuse by limiting requests per IP and per template.
 */
readonly class RateLimitMiddleware implements MiddlewareInterface
{
	private const CACHE_PREFIX = 'rate_limit_';

	public function __construct(
		private APCuService $cache,
		private JsonRenderer $renderer,
		private Config $config,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		// Get rate limit config (cast to int since JSON settings come as strings)
		$mailerConfig     = $this->config->mailer ?? [];
		$perIpLimit       = (int) ($mailerConfig['ratePerIp'] ?? 10);
		$perTemplateLimit = (int) ($mailerConfig['ratePerTemplate'] ?? 50);
		$window           = (int) ($mailerConfig['rateWindow'] ?? 300); // 5 minutes

		// Get identifier (IP address)
		$ip    = $this->getClientIp($request);
		$ipKey = self::CACHE_PREFIX . 'ip_' . md5($ip);

		// Get template ID from request body
		$data        = (array)$request->getParsedBody();
		$mailerId    = $data['mailerId'] ?? '';
		$templateKey = $mailerId !== '' ? self::CACHE_PREFIX . 'template_' . md5((string)$mailerId) : '';

		// Check IP rate limit
		$ipCount = $this->getCount($ipKey);
		if ($ipCount >= $perIpLimit) {
			return $this->createRateLimitResponse($perIpLimit, $window, 'IP');
		}

		// Check template rate limit if we have a template ID
		if ($templateKey !== '') {
			$templateCount = $this->getCount($templateKey);
			if ($templateCount >= $perTemplateLimit) {
				return $this->createRateLimitResponse($perTemplateLimit, $window, 'Template');
			}
		}

		// Increment counters
		$this->incrementCount($ipKey, $window);
		if ($templateKey !== '') {
			$this->incrementCount($templateKey, $window);
		}

		// Add rate limit headers
		$response = $handler->handle($request);

		return $response
			->withHeader('X-RateLimit-IP-Limit', (string)$perIpLimit)
			->withHeader('X-RateLimit-IP-Remaining', (string)max(0, $perIpLimit - $ipCount - 1))
			->withHeader('X-RateLimit-Window', (string)$window);
	}

	private function getClientIp(ServerRequestInterface $request): string
	{
		// Check for Cloudflare IP first
		if ($request->hasHeader('CF-Connecting-IP')) {
			return $request->getHeaderLine('CF-Connecting-IP');
		}

		// Check for forwarded IP
		if ($request->hasHeader('X-Forwarded-For')) {
			$ips = explode(',', $request->getHeaderLine('X-Forwarded-For'));

			return trim($ips[0]);
		}

		// Get from server params
		$serverParams = $request->getServerParams();

		return $serverParams['REMOTE_ADDR'] ?? '0.0.0.0';
	}

	private function getCount(string $key): int
	{
		if (!$this->cache->isAvailable()) {
			return 0; // Skip rate limiting if APCu not available
		}

		$count = $this->cache->get($key);

		return is_int($count) ? $count : 0;
	}

	private function incrementCount(string $key, int $ttl): void
	{
		if (!$this->cache->isAvailable()) {
			return; // Skip if APCu not available
		}

		$current = $this->getCount($key);
		$this->cache->set($key, $current + 1, $ttl);
	}

	private function createRateLimitResponse(int $limit, int $window, string $type): ResponseInterface
	{
		$retryAfter = $window;

		return $this->renderer->json(
			(new \Slim\Psr7\Response())->withStatus(429),
			[
				'success'     => false,
				'message'     => 'Rate limit exceeded',
				'error'       => "Too many requests from this {$type}. Please try again later.",
				'retry_after' => $retryAfter,
				'limit'       => $limit,
				'window'      => $window,
			]
		)
		->withHeader('Retry-After', (string)$retryAfter)
		->withHeader('X-RateLimit-Limit', (string)$limit)
		->withHeader('X-RateLimit-Window', (string)$window);
	}
}
