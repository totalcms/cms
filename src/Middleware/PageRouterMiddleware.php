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
	/**
	 * Map of common file extensions to their MIME types.
	 * Used to auto-detect Content-Type for routes like /robots.txt, /llms.txt, etc.
	 *
	 * @var array<string,string>
	 */
	private const EXTENSION_CONTENT_TYPES = [
		'css'  => 'text/css; charset=utf-8',
		'csv'  => 'text/csv; charset=utf-8',
		'js'   => 'application/javascript; charset=utf-8',
		'json' => 'application/json; charset=utf-8',
		'md'   => 'text/markdown; charset=utf-8',
		'rss'  => 'application/rss+xml; charset=utf-8',
		'svg'  => 'image/svg+xml',
		'txt'  => 'text/plain; charset=utf-8',
		'xml'  => 'application/xml; charset=utf-8',
	];

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

			$body = $this->twigEngine->render($match->template, $data);

			$pageResponse = new Response();
			$pageResponse->getBody()->write($body);

			return $pageResponse->withHeader('Content-Type', $this->detectContentType($path));
		} catch (\Throwable) {
			// Render failed — return the original 404
			return $response;
		}
	}

	/**
	 * Auto-detect the Content-Type from the route's file extension.
	 *
	 * `/robots.txt` → text/plain, `/sitemap.xml` → application/xml, etc.
	 * Routes without a recognized extension default to HTML.
	 */
	private function detectContentType(string $path): string
	{
		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

		return self::EXTENSION_CONTENT_TYPES[$extension] ?? 'text/html; charset=utf-8';
	}
}
