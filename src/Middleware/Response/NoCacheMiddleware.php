<?php

namespace TotalCMS\Middleware\Response;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware to prevent browser caching of responses.
 * Used for admin routes to ensure users always see fresh content.
 */
class NoCacheMiddleware implements MiddlewareInterface
{
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$response = $handler->handle($request);

		return $response
			->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0')
			->withHeader('Pragma', 'no-cache')
			->withHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT');
	}
}
