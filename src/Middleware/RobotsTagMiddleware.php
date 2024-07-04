<?php

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Allow indexing of images.
 */
final class RobotsTagMiddleware implements MiddlewareInterface
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

		return $response->withoutHeader('X-Robots-Tag');
	}
}
