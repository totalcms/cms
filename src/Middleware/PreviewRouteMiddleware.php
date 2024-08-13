<?php

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Support\Config;

/**
 * Stacks Preview middleware.
 *
 * A special middleware that allows to preview a page by passing the "route" query parameter.
 */
final class PreviewRouteMiddleware implements MiddlewareInterface
{
	public function __construct(
		private Config $config,
	) {
	}

	/**
	 * @SuppressWarnings(PHPMD.Superglobals)
	 *
	 * @param ServerRequestInterface $request
	 * @param RequestHandlerInterface $handler
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$routePath = $this->getPreviewRoute($request);

		// Update the request path to match the "route" query parameter
		$uri     = $request->getUri()->withPath($routePath);
		$request = $request->withUri($uri);

		$_SERVER['PREVIEW_TCMSDIR'] = $this->config->datadir;

		$response = $handler->handle($request);

		return $response;
	}

	private function getPreviewRoute(ServerRequestInterface $request): string
	{
		$server = $request->getServerParams();
		$url = isset($server['REQUEST_URI']) ? $server['REQUEST_URI'] : '';

		// Find the position of "tcms/public" in the request URI
		$parts = explode('tcms/public', $url);
		$route = $parts[1] ?? '';

		return $route;
	}
}
