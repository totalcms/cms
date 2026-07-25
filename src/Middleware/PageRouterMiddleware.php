<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use TotalCMS\Domain\Builder\Data\PageData;
use TotalCMS\Domain\Builder\Service\PageInspectorRenderer;
use TotalCMS\Domain\Builder\Service\PageMiddlewareRunner;
use TotalCMS\Domain\Builder\Service\PageReloadInjectorRenderer;
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
	 * Request attribute marking a request this middleware may augment into a
	 * rendered builder page. Every builder-page view starts life as a Slim
	 * routing 404, so the error handler must not ERROR-log (or Sentry-report)
	 * these — the middleware logs genuine misses itself at info level.
	 */
	public const AUGMENTS_404 = 'tcms_page_router_augments_404';

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

	private \Psr\Log\LoggerInterface $logger;

	public function __construct(
		private PageRouter $pageRouter,
		private TwigEngine $twigEngine,
		private PageMiddlewareRunner $pageMiddlewareRunner,
		private PageInspectorRenderer $pageInspector,
		private PageReloadInjectorRenderer $pageReloadInjector,
		\TotalCMS\Factory\LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(\TotalCMS\Factory\LogChannel::App);
	}

	public function process(
		ServerRequestInterface $request,
		RequestHandlerInterface $handler,
	): ResponseInterface {
		// Page rendering is GET-only, but page-feature middleware (e.g. the
		// Protect passcode form) needs to handle its own POST submissions back to
		// the page URL — Slim has no POST route for a builder page, so those land
		// here as 404s. So GET and POST both run the page-middleware chain below;
		// a POST simply never falls through to rendering (if no middleware handles
		// it, the 404 stands). Other verbs have no page semantics — pass through.
		//
		// Admin and API 404s are never overridden with the public fallback page:
		// /admin/* has its own Admin404Action; /api/* returns JSON 404s that
		// should never be replaced with a rendered builder page.
		$method = $request->getMethod();
		$path   = $request->getUri()->getPath();

		$mayAugment = ($method === 'GET' || $method === 'POST')
			&& $path !== '/admin' && !str_starts_with($path, '/admin/')
			&& $path !== '/api' && !str_starts_with($path, '/api/');

		if ($mayAugment) {
			// Tell the error handler this routing 404 may become a page render —
			// it must not be ERROR-logged or reported (see AUGMENTS_404).
			$request = $request->withAttribute(self::AUGMENTS_404, true);
		}

		// Let Slim handle the request first
		$response = $handler->handle($request);

		// We only ever augment Slim 404s.
		if ($response->getStatusCode() !== 404 || !$mayAugment) {
			return $response;
		}

		// Try to match a builder page or collection URL
		$match = $this->pageRouter->match($path);

		if (!$match instanceof \TotalCMS\Domain\Builder\Data\RouteMatch) {
			// A genuine miss — the routing 404 above was suppressed from the
			// error log, so record it here without the exception theatrics.
			$this->logger->info(sprintf('404: %s %s matched no route, builder page, or collection URL', $method, $path));

			// Fall back to the page flagged as the universal 404 (if any). Lets
			// users ship a custom-styled 404 from the admin without touching
			// code; the page's own status field controls the response code.
			// GET only: the fallback renders an HTML page, which a POST must never do.
			if ($method === 'GET') {
				$match = $this->pageRouter->fallback404();
			}
		}

		if (!$match instanceof \TotalCMS\Domain\Builder\Data\RouteMatch) {
			return $response;
		}

		// Redirect — when the page status is a 3xx and redirectTo is set, send
		// a Location header instead of rendering. Lets users move/replace URLs
		// from the admin without writing route files. Runs before middleware
		// because a redirected page has nothing to gate. GET only — a redirect
		// is a rendering decision, not a gate.
		if ($method === 'GET' && $match->redirectTo !== '' && $match->status >= 300 && $match->status < 400) {
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
			$page               = new PageData($match->pageData);
			$middlewareResponse = $this->pageMiddlewareRunner->run($request, $page);
			if ($middlewareResponse instanceof ResponseInterface) {
				// A short-circuiting middleware (e.g. ab-split variant B) may
				// have rendered HTML at this URL. Inject the inspector into
				// that HTML too — otherwise the chip would silently disappear
				// for whichever variant was served via short-circuit.
				return $this->injectInspectorIfHtml($middlewareResponse, $request, $match);
			}
		}

		// A POST that no page-feature middleware handled has nowhere to go —
		// pages only render on GET. Return the original 404 untouched.
		if ($method !== 'GET') {
			return $response;
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

			$body        = $this->twigEngine->render($match->template, $data);
			$contentType = $this->detectContentType($path);

			// Inject the admin-only Page Inspector overlay and live-reload
			// snippet for HTML responses. Gating (logged-in admin, dismiss
			// cookie, setting toggle) lives in the renderers; the content-
			// type check is here because the renderers don't see the response.
			if (str_starts_with($contentType, 'text/html')) {
				$body = $this->pageInspector->maybeInject($body, $request, $match);
				$body = $this->pageReloadInjector->maybeInject($body, $request);
			}

			$pageResponse = new Response();
			$pageResponse->getBody()->write($body);

			return $pageResponse
				->withStatus($match->status)
				->withHeader('Content-Type', $contentType);
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
	 * Inject the Page Inspector chip into a Response's body if the response
	 * is HTML. Used when a page-feature middleware short-circuits the chain
	 * with its own response (e.g. ab-split variant B) — without this, the
	 * inspector would silently disappear for whichever variant skipped the
	 * normal render branch.
	 *
	 * Reads the body, runs `maybeInject`, and writes a fresh body back.
	 * Returns the response unchanged for non-HTML content types.
	 */
	private function injectInspectorIfHtml(
		ResponseInterface $response,
		ServerRequestInterface $request,
		\TotalCMS\Domain\Builder\Data\RouteMatch $match,
	): ResponseInterface {
		$contentType = strtolower($response->getHeaderLine('Content-Type'));
		if (!str_starts_with($contentType, 'text/html')) {
			return $response;
		}

		$body     = (string)$response->getBody();
		$injected = $this->pageInspector->maybeInject($body, $request, $match);
		$injected = $this->pageReloadInjector->maybeInject($injected, $request);
		if ($injected === $body) {
			return $response;
		}

		$stream = (new \Nyholm\Psr7\Factory\Psr17Factory())->createStream($injected);

		return $response->withBody($stream);
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
