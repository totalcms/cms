<?php

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;
use Slim\Psr7\Response;

/**
 * CORS middleware.
 *
 * Allows CORS preflight from any domain.
 */
final class BetaMiddleware implements MiddlewareInterface
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
		$response = new Response();
		$response->getBody()->write('Total CMS Beta has expired');

		$expireFile = __DIR__ . '/../../resources/.beta';
		if (!file_exists($expireFile)) {
			return $response->withStatus(403);
		}

		$expireDate = file_get_contents($expireFile);
		if ($expireDate === false || time() > strtotime(base64_decode($expireDate))) {
			return $response->withStatus(403);
		}

		return $handler->handle($request);
	}
}
