<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use TotalCMS\Domain\Builder\Data\PageData;
use TotalCMS\Domain\Builder\Service\PageMiddlewareRunner;
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
		'css'         => 'text/css; charset=utf-8',
		'csv'         => 'text/csv; charset=utf-8',
		'js'          => 'application/javascript; charset=utf-8',
		'json'        => 'application/json; charset=utf-8',
		'md'          => 'text/markdown; charset=utf-8',
		'rss'         => 'application/rss+xml; charset=utf-8',
		'svg'         => 'image/svg+xml',
		'txt'         => 'text/plain; charset=utf-8',
		'webmanifest' => 'application/manifest+json',
		'xml'         => 'application/xml; charset=utf-8',
	];

	public function __construct(
		private PageRouter $pageRouter,
		private TwigEngine $twigEngine,
		private PageMiddlewareRunner $pageMiddlewareRunner,
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

		// Nothing matched — fall back to the page flagged as the universal 404
		// (if any). Lets users ship a custom-styled 404 from the admin without
		// touching code; the page's own status field controls the response code.
		if (!$match instanceof \TotalCMS\Domain\Builder\Data\RouteMatch) {
			$match = $this->pageRouter->fallback404();
		}

		if (!$match instanceof \TotalCMS\Domain\Builder\Data\RouteMatch) {
			return $response;
		}

		// Redirect — when the page status is a 3xx and redirectTo is set, send
		// a Location header instead of rendering. Lets users move/replace URLs
		// from the admin without writing route files. Runs before middleware
		// because a redirected page has nothing to gate.
		if ($match->redirectTo !== '' && $match->status >= 300 && $match->status < 400) {
			return (new Response())
				->withStatus($match->status)
				->withHeader('Location', $match->redirectTo);
		}

		// Per-page middleware chain — auth gates, rate limits, etc. Only
		// applies to builder-page matches (collection-URL matches don't
		// have a per-record middleware concept yet). The runner returns a
		// Response if any middleware short-circuits; null means proceed.
		//
		// Runs BEFORE the headless-JSON shortcut: gating still has to apply
		// to JSON consumers, otherwise an auth-protected page would happily
		// hand its data to a logged-out visitor that asks for `?_format=json`.
		if ($match->collection === null) {
			$page = new PageData($match->pageData);
			$middlewareResponse = $this->pageMiddlewareRunner->run($request, $page);
			if ($middlewareResponse !== null) {
				return $middlewareResponse;
			}
		}

		// Headless mode — return the matched page record as JSON instead of rendering
		if ($this->wantsJson($request)) {
			return $this->jsonResponse($match);
		}

		try {
			// Collection-URL matches expose the matched record as `object.*`;
			// builder-page matches expose it as `page.*`. Different conceptual
			// roles — one is "this is the page record", the other is "this is
			// the collection object I'm rendering" — so different variable
			// names make templates self-documenting.
			$data = ['params' => $match->params];
			if ($match->collection !== null) {
				$data['object'] = $match->pageData;
			} else {
				$data['page'] = $match->pageData;
			}

			$body = $this->twigEngine->render($match->template, $data);

			$pageResponse = new Response();
			$pageResponse->getBody()->write($body);

			return $pageResponse
				->withStatus($match->status)
				->withHeader('Content-Type', $this->detectContentType($path));
		} catch (\Throwable) {
			// Render failed — return the original 404
			return $response;
		}
	}

	/**
	 * Detect whether the client wants the matched page as JSON instead of HTML.
	 * Triggered by `?_format=json` query param or `Accept: application/json`.
	 *
	 * Lets the same builder pages drive both web and a headless head (Next.js,
	 * Astro, etc.) without a separate API surface.
	 */
	private function wantsJson(ServerRequestInterface $request): bool
	{
		parse_str($request->getUri()->getQuery(), $query);
		if (($query['_format'] ?? '') === 'json') {
			return true;
		}

		$accept = $request->getHeaderLine('Accept');
		if ($accept === '') {
			return false;
		}

		// Accept: application/json wins; */* or text/html don't trigger headless mode
		return str_contains(strtolower($accept), 'application/json');
	}

	/**
	 * Encode a RouteMatch as a JSON response.
	 */
	private function jsonResponse(\TotalCMS\Domain\Builder\Data\RouteMatch $match): ResponseInterface
	{
		// Mirror the template's variable convention for consumers — a
		// collection-URL match returns the matched record under `object`,
		// a builder-page match returns it under `page`.
		$payload = [
			'params'     => $match->params,
			'template'   => $match->template,
			'collection' => $match->collection,
			'status'     => $match->status,
		];
		if ($match->collection !== null) {
			$payload['object'] = $match->pageData;
		} else {
			$payload['page'] = $match->pageData;
		}

		$response = new Response();
		$response->getBody()->write((string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

		return $response
			->withStatus($match->status)
			->withHeader('Content-Type', 'application/json; charset=utf-8');
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
