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
final class PreviewRouteMiddleware implements MiddlewareInterface
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
        $queryParams = $request->getQueryParams();

        // Check if the "route" query parameter is present
        if (isset($queryParams['route'])) {
            // Update the request path to match the "route" query parameter
            $routePath = '/' . ltrim($queryParams['route'], '/');
            $uri       = $request->getUri()->withPath($routePath);
            $request   = $request->withUri($uri);
        }
        if (isset($queryParams['datadir'])) {
            $_SERVER['TCMSDIR'] = $queryParams['datadir'];
        }

        $response = $handler->handle($request);

        return $response;
    }
}
