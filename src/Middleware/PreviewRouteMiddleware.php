<?php

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Stacks Preview middleware.
 *
 * A special middleware that allows to preview a page by passing the "route" query parameter.
 */
readonly class PreviewRouteMiddleware implements MiddlewareInterface
{
	public function __construct(private string $api)
	{
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$route = $this->getPreviewRoute($request);

		if ($route !== '') {
			// Update the request path to match the "route" query parameter
			$uri     = $request->getUri()->withPath($route);
			$request = $request->withUri($uri);
		}

		return $handler->handle($request);
	}

	private function getPreviewRoute(ServerRequestInterface $request): string
	{
		$url = $request->getUri()->getPath();
		$api = $this->api ?: 'tcms/public';

		$parts = explode($api, $url);

		return $parts[1] ?? '';
	}
}
