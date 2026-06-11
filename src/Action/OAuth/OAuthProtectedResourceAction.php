<?php

declare(strict_types=1);

namespace TotalCMS\Action\OAuth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\OAuth\Service\OAuthDiscoveryProvider;
use TotalCMS\Renderer\JsonRenderer;

/**
 * RFC 9728 OAuth 2.0 Protected Resource Metadata at
 * `/.well-known/oauth-protected-resource`.
 *
 * MCP clients that receive a 401 from the MCP endpoint follow the
 * `WWW-Authenticate: ... resource_metadata="..."` pointer here to learn which
 * authorization server protects the resource. Mirrors OAuthDiscoveryAction's
 * non-Pro behavior: 404 when the OAuth server feature is unavailable.
 */
readonly class OAuthProtectedResourceAction
{
	public function __construct(
		private OAuthDiscoveryProvider $provider,
		private JsonRenderer $renderer,
		private EditionFeatureService $editionFeatures,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		if (!$this->editionFeatures->can(EditionFeature::OAUTH_SERVER)) {
			return $response->withStatus(404);
		}

		return $this->renderer->json($response, $this->provider->protectedResourceMetadata());
	}
}
