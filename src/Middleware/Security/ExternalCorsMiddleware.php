<?php

namespace TotalCMS\Middleware\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Slim\Routing\RouteContext;

/**
 * CORS middleware for external API routes only.
 *
 * Applied to route groups that allow external access (API key or designer token).
 * Does NOT allow credentials — external consumers authenticate via API key headers.
 */
class ExternalCorsMiddleware implements MiddlewareInterface
{
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		// Handle preflight OPTIONS requests immediately
		if (strtoupper($request->getMethod()) === 'OPTIONS') {
			$routeContext   = RouteContext::fromRequest($request);
			$routingResults = $routeContext->getRoutingResults();
			$methods        = $routingResults->getAllowedMethods();

			return $this->addCorsHeaders(new Response(), $methods);
		}

		$response = $handler->handle($request);

		return $this->addCorsHeaders($response);
	}

	/**
	 * Add CORS headers to a response.
	 *
	 * @param ResponseInterface $response The response
	 * @param array<string>|null $methods Allowed methods (for preflight only)
	 *
	 * @return ResponseInterface
	 */
	private function addCorsHeaders(ResponseInterface $response, ?array $methods = null): ResponseInterface
	{
		$response = $response->withHeader('Access-Control-Allow-Origin', '*');
		$response = $response->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-API-Key, X-Designer-Token');

		if ($methods !== null) {
			$response = $response->withHeader('Access-Control-Allow-Methods', implode(',', $methods));
			$response = $response->withHeader('Access-Control-Max-Age', '86400');
		}

		return $response;
	}
}
