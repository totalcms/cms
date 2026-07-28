<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Security;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\XmlRpc\Transport\XmlRpcResponseWriter;
use TotalCMS\Renderer\RawRenderer;
use TotalCMS\Support\Config;

/**
 * Per-IP cap on XML-RPC calls.
 *
 * The endpoint sits at a path bots probe unprompted, and the XML parser runs
 * before any credential check, so this bounds how much pre-auth work an
 * anonymous caller can force. Rejections are XML-RPC faults rather than JSON:
 * the caller is a writing app that only parses XML. Fail-open when the cache is
 * unreachable, matching RateLimitMiddleware.
 */
readonly class XmlRpcRateLimitMiddleware implements MiddlewareInterface
{
	private const CACHE_PREFIX = 'xmlrpc_rate_';
	private const WINDOW       = 60;

	public function __construct(
		private CacheManager $cache,
		private Config $config,
		private XmlRpcResponseWriter $writer,
		private RawRenderer $renderer,
		private ResponseFactoryInterface $responseFactory,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$limit = (int)($this->config->xmlrpc['ratePerIp'] ?? 60);

		if ($limit <= 0) {
			return $handler->handle($request);
		}

		$key   = self::CACHE_PREFIX . md5($this->clientIp($request));
		$count = $this->getCount($key);

		if ($count >= $limit) {
			$response = $this->responseFactory->createResponse(429)
				->withHeader('Content-Type', 'text/xml; charset=UTF-8')
				->withHeader('Retry-After', (string)self::WINDOW);

			return $this->renderer->render(
				$response,
				$this->writer->fault(429, 'Too many XML-RPC requests. Try again in a minute.')
			);
		}

		$this->incrementCount($key, self::WINDOW);

		return $handler->handle($request);
	}

	private function getCount(string $key): int
	{
		// getData() returns null when the key is absent or every backend is
		// unreachable — both collapse to zero so the limiter fails open rather
		// than blocking traffic on a broken cache.
		$count = $this->cache->getData($key);

		return is_int($count) ? $count : 0;
	}

	private function incrementCount(string $key, int $ttl): void
	{
		$this->cache->storeData($key, $this->getCount($key) + 1, $ttl);
	}

	private function clientIp(ServerRequestInterface $request): string
	{
		if ($request->hasHeader('CF-Connecting-IP')) {
			return $request->getHeaderLine('CF-Connecting-IP');
		}

		if ($request->hasHeader('X-Forwarded-For')) {
			return trim(explode(',', $request->getHeaderLine('X-Forwarded-For'))[0]);
		}

		$server = $request->getServerParams();

		return (string)($server['REMOTE_ADDR'] ?? 'unknown');
	}
}
