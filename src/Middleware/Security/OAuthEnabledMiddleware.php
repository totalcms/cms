<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Security;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

/**
 * Gates every OAuth server route behind $config->oauth['enabled'].
 *
 * Turning OAuth off lets a site run public-only MCP: Claude's consumer apps
 * front-load a login prompt whenever OAuth is discoverable — even though the
 * anonymous tier would serve them — and their consent screen is impassable
 * for visitors without an operator account. With the well-knowns 404ing,
 * those clients connect anonymously like to any no-auth MCP server.
 *
 * Applied to discovery AND issuance endpoints alike: hiding the metadata
 * while leaving /oauth/token alive would let previously-issued refresh
 * tokens keep minting access tokens forever. Already-issued access tokens
 * outlive the flip by at most their TTL (default 1 hour) — the bearer
 * middleware still validates them, but with refresh dead the tier drains
 * itself. 404 (not 403) so clients read "no OAuth here" through standard
 * discovery failure, mirroring how the well-knowns respond on non-Pro
 * editions.
 */
readonly class OAuthEnabledMiddleware implements MiddlewareInterface
{
	public function __construct(
		private Config $config,
		private JsonRenderer $renderer,
		private ResponseFactoryInterface $responseFactory,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		if (($this->config->oauth['enabled'] ?? true) === true) {
			return $handler->handle($request);
		}

		return $this->renderer->json($this->responseFactory->createResponse(), [
			'error'             => 'not_found',
			'error_description' => 'OAuth is disabled on this server.',
		], 404);
	}
}
