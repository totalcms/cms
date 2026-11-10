<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Renderer\ForbiddenRenderer;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\BasePath;
use TotalCMS\Support\Config;

/**
 * Keeps a demo site read-only (`$settings['demo']['enable']`).
 *
 * Every request that could change something is refused, whatever it would
 * have done: saves, deletes, uploads, imports, cache clears, job runs, and
 * the Twig Playground and Debugger, whose renders run code on the server.
 * The rule is the HTTP method, not a list of routes, so a route added later
 * is covered without anyone remembering to add it here. Sign-in is off in
 * demo mode (Config::authEnabled), so this is the only thing standing
 * between an anonymous visitor and the site's data.
 *
 * The log pages are hidden as well: they list the IP addresses of everyone
 * who has visited, and on a public demo that is other visitors.
 *
 * Registered inside the routing layer, after MethodOverrideMiddleware has
 * run, so a POST that claims to be a GET through `_METHOD` is judged by the
 * method it claims, which is the one the route sees.
 */
final readonly class DemoModeMiddleware implements MiddlewareInterface
{
	/** Methods that only read. Everything else is refused. */
	private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

	/** Utilities pages that list visitors' IP addresses: the Log Analyzer. */
	public const HIDDEN_UTILS = ['logs'];

	public function __construct(
		private Config $config,
		private TranslationService $translator,
		private TwigRenderer $twigRenderer,
		private JsonRenderer $jsonRenderer,
		private ResponseFactoryInterface $responseFactory,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		if (!$this->config->demoEnabled()) {
			return $handler->handle($request);
		}

		if (self::isHiddenPage($this->path($request))) {
			throw new HttpNotFoundException($request);
		}

		if (!in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true)) {
			return $this->refuse($request);
		}

		return $handler->handle($request);
	}

	/** Whether demo mode hides this utility page from the admin. */
	public static function hidesUtil(string $page): bool
	{
		return in_array($page, self::HIDDEN_UTILS, true);
	}

	/** A hidden utility page, or anything under it (the log download). */
	private static function isHiddenPage(string $path): bool
	{
		foreach (self::HIDDEN_UTILS as $page) {
			$prefix = '/admin/utils/' . $page;
			if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The refusal, in the form the caller can show: an HTML fragment for HTMX
	 * (HTMX 4 swaps a 403 into the target, so the message lands where the
	 * result would have), the access-denied page for a plain admin form post,
	 * and JSON with an `error` string for everything else, which is what the
	 * admin's API calls read their message from (api-error.js).
	 */
	private function refuse(ServerRequestInterface $request): ResponseInterface
	{
		$message  = $this->translator->trans('demo.refused');
		$response = $this->responseFactory->createResponse(403);

		if ($request->getHeaderLine('HX-Request') !== '') {
			$response->getBody()->write(sprintf(
				'<div class="warning-box demo-refused" role="alert"><p>%s</p></div>',
				htmlspecialchars($message, ENT_QUOTES, 'UTF-8'),
			));

			return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
		}

		if (str_contains($request->getHeaderLine('Accept'), 'text/html') && ForbiddenRenderer::isAdminUi($request)) {
			return (new ForbiddenRenderer($this->twigRenderer, $this->jsonRenderer, $this->responseFactory))
				->forbidden($request, $message);
		}

		return $this->jsonRenderer->json($response, ['error' => $message]);
	}

	private function path(ServerRequestInterface $request): string
	{
		$basePath = $request->getAttribute(RouteContext::BASE_PATH);

		return BasePath::strip(is_string($basePath) ? $basePath : '', $request->getUri()->getPath());
	}
}
