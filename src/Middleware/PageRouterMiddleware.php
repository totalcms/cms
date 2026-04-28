<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use TotalCMS\Domain\Builder\Service\PageRouter;
use TotalCMS\Domain\Twig\Service\TwigEngine;

/**
 * Page routing middleware — wraps the entire Slim pipeline.
 *
 * Lets Slim handle API/admin routes first. If Slim returns a 404,
 * tries to match the request against builder page routes and collection URLs.
 * This ensures API routes always take priority over builder pages.
 */
readonly class PageRouterMiddleware implements MiddlewareInterface
{
	public function __construct(
		private PageRouter $pageRouter,
		private TwigEngine $twigEngine,
	) {
	}

	public function process(
		ServerRequestInterface $request,
		RequestHandlerInterface $handler,
	): ResponseInterface {
		// Let Slim handle the request first
		$response = $handler->handle($request);

		// Only intercept 404s on GET requests
		if ($request->getMethod() !== 'GET' || $response->getStatusCode() !== 404) {
			return $response;
		}

		// Try to match a builder page or collection URL
		$path  = $request->getUri()->getPath();
		$match = $this->pageRouter->match($path);

		if (!$match instanceof \TotalCMS\Domain\Builder\Data\RouteMatch) {
			return $response;
		}

		try {
			$data = [
				'page'   => $match->pageData,
				'params' => $match->params,
			];

			$html = $this->twigEngine->render($match->template, $data);

			$pageResponse = new Response();
			$pageResponse->getBody()->write($html);

			return $pageResponse->withHeader('Content-Type', 'text/html; charset=utf-8');
		} catch (\Throwable) {
			// Render failed — return the original 404
			return $response;
		}
	}
}
