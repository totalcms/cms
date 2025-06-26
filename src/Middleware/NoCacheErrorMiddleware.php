<?php

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware to prevent caching of error responses at the browser and proxy level.
 * This ensures that once errors are fixed, browsers don't serve cached error pages.
 */
final class NoCacheErrorMiddleware implements MiddlewareInterface
{
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$response = $handler->handle($request);

		// Add no-cache headers for error responses (4xx and 5xx status codes)
		$statusCode = $response->getStatusCode();
		if ($statusCode >= 400) {
			$response = $response
				->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0')
				->withHeader('Pragma', 'no-cache')
				->withHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT')
				->withHeader('X-Cache-Control', 'error-no-cache');
		}

		return $response;
	}
}
