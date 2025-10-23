<?php

namespace TotalCMS\Middleware\Response;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Allow indexing of images.
 */
class RobotsTagMiddleware implements MiddlewareInterface
{
	/**
	 * Invoke middleware.
	 *
	 * @param ServerRequestInterface $request The request
	 * @param RequestHandlerInterface $handler The handler
	 *
	 * @return ResponseInterface The response
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$response = $handler->handle($request);

		$currentRoute = $request->getUri()->getPath();
		if (str_starts_with($currentRoute, '/imageworks')) {
			// allow indexing of images
			return $response->withHeader('X-Robots-Tag', 'index, follow');
		}

		// Most CMS routes should not be indexed
		return $response->withHeader('X-Robots-Tag', 'noindex, nofollow');
	}
}
