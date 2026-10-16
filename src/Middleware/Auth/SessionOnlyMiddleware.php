<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Auth;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Rejects API-key and OAuth bearer callers; only browser sessions pass.
 *
 * Installing or removing an extension means putting code on the server, which
 * is an operator action. API keys (which access middleware lets skip the
 * admin check) and agent tokens are deliberately excluded. Mount it after an
 * authenticating middleware so `authMethod` is already set. A session login
 * leaves the attribute absent (or `session`), which passes through.
 *
 * With authentication switched off AuthMiddleware opens the whole admin —
 * templates and automation handlers included — so this middleware does not
 * second-guess that; it only tells session callers from token callers.
 */
readonly class SessionOnlyMiddleware implements MiddlewareInterface
{
	public function __construct(
		private ResponseFactoryInterface $responseFactory,
		private JsonRenderer $renderer,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$method = $request->getAttribute('authMethod');
		if ($method === 'apikey' || $method === 'oauth_bearer') {
			return $this->renderer->json(
				$this->responseFactory->createResponse(),
				['error' => 'This endpoint accepts browser sessions only. Use the tcms CLI for scripted access.'],
				403,
			);
		}

		return $handler->handle($request);
	}
}
