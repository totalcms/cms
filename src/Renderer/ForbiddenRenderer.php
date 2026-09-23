<?php

declare(strict_types=1);

namespace TotalCMS\Renderer;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Support\BasePath;

/**
 * The one 403 responder: the access-denied page for the admin UI, a JSON
 * error for everything else.
 *
 * The access, edition and dual-auth middlewares each carried a copy of this
 * decision, and every copy compared the raw request path against `/admin/`
 * — so on a sub-folder install (`/site/admin/…`) an admin page denial came
 * back as JSON. The path is made relative to the mount point first here.
 */
final readonly class ForbiddenRenderer
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private JsonRenderer $jsonRenderer,
		private ResponseFactoryInterface $responseFactory,
	) {
	}

	/**
	 * Whether the request came from the admin UI (as opposed to the API).
	 *
	 * Slim's RouteRunner puts the app base path on the request before any
	 * route middleware runs, so this is base-path aware wherever it is
	 * reached from a route. Outside routing (no attribute) the path is used
	 * as is, which is right for a root install.
	 */
	public static function isAdminUi(ServerRequestInterface $request): bool
	{
		$basePath = $request->getAttribute(RouteContext::BASE_PATH);
		$path     = BasePath::strip(is_string($basePath) ? $basePath : '', $request->getUri()->getPath());

		return str_starts_with($path, '/admin/');
	}

	/**
	 * @param string|null $details extra diagnostics shown on the admin page only (dev environments)
	 */
	public function forbidden(ServerRequestInterface $request, string $message, ?string $details = null): ResponseInterface
	{
		$response = $this->responseFactory->createResponse()->withStatus(403);

		if (self::isAdminUi($request)) {
			return $this->twigRenderer->template($response, 'access-denied.twig', [
				'message'  => $message,
				'details'  => $details,
				'referrer' => $request->getHeaderLine('Referer') ?: null,
			]);
		}

		return $this->jsonRenderer->json($response, ['error' => ['message' => $message]]);
	}
}
