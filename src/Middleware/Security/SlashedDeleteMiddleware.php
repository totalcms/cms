<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Security;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Refuses a content delete whose URL ends in a slash.
 *
 * Such a URL was built from an empty last part: `…/{property}/` with no file
 * name, `…/{collection}/` with no object id. TrailingSlash drops the slash, so
 * the request would delete the thing one level up instead: a gallery thumbnail
 * that had no name yet deleted every image in its gallery.
 *
 * Runs after MethodOverrideMiddleware (the admin sends deletes as POST) and
 * before TrailingSlash, the only place both the method and the slash are
 * known. Content routes only: an MCP client may end its session with a DELETE
 * to `/mcp/`.
 */
readonly class SlashedDeleteMiddleware implements MiddlewareInterface
{
	public function __construct(
		private ResponseFactoryInterface $responseFactory,
		private JsonRenderer $renderer,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$path = $request->getUri()->getPath();

		if ($request->getMethod() === 'DELETE' && str_ends_with($path, '/') && str_contains($path, '/collections/')) {
			return $this->renderer->json(
				$this->responseFactory->createResponse(400),
				['error' => ['message' => 'Nothing was deleted: the URL ends in a slash, so the name of what to delete is missing.']],
			);
		}

		return $handler->handle($request);
	}
}
