<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Security;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Security\RateLimit\RateLimiter;
use TotalCMS\Domain\Security\Request\ClientIpResolver;
use TotalCMS\Domain\XmlRpc\Transport\XmlRpcResponseWriter;
use TotalCMS\Renderer\RawRenderer;
use TotalCMS\Support\Config;

/**
 * Per-IP cap on XML-RPC calls.
 *
 * The endpoint sits at a path bots probe unprompted, and the XML parser runs
 * before any credential check, so this bounds how much pre-auth work an
 * anonymous caller can force. Rejections are XML-RPC faults rather than JSON:
 * the caller is a writing app that only parses XML. Counting is `RateLimiter`'s,
 * which fails open when the cache is unreachable.
 */
readonly class XmlRpcRateLimitMiddleware implements MiddlewareInterface
{
	private const WINDOW = 60;

	public function __construct(
		private RateLimiter $limiter,
		private Config $config,
		private XmlRpcResponseWriter $writer,
		private RawRenderer $renderer,
		private ResponseFactoryInterface $responseFactory,
		private ClientIpResolver $clientIpResolver,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$limit = (int)($this->config->xmlrpc['ratePerIp'] ?? 60);

		if ($limit <= 0) {
			return $handler->handle($request);
		}

		$result = $this->limiter->attempt('xmlrpc', $this->clientIpResolver->resolve($request), $limit, self::WINDOW);

		if (!$result->allowed) {
			$response = $this->responseFactory->createResponse(429)
				->withHeader('Content-Type', 'text/xml; charset=UTF-8')
				->withHeader('Retry-After', (string)$result->retryAfter);

			return $this->renderer->render(
				$response,
				$this->writer->fault(429, 'Too many XML-RPC requests. Try again in a minute.')
			);
		}

		return $handler->handle($request);
	}
}
