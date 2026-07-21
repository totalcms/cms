<?php

declare(strict_types=1);

namespace TotalCMS\Action\OAuth;

use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadataHandler;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\OAuth\Service\OAuthDiscoveryProvider;

/**
 * RFC 9728 OAuth 2.0 Protected Resource Metadata at
 * `/.well-known/oauth-protected-resource`.
 *
 * MCP clients that receive a 401 from the MCP endpoint follow the
 * `WWW-Authenticate: ... resource_metadata="..."` pointer here to learn which
 * authorization server protects the resource.
 *
 * Serving is delegated to the SDK's ProtectedResourceMetadataHandler so the
 * response shape tracks the spec; what stays ours is the metadata content
 * (OAuthDiscoveryProvider) and the edition gate — mirrors OAuthDiscoveryAction's
 * non-Pro behavior: 404 when the OAuth server feature is unavailable.
 */
readonly class OAuthProtectedResourceAction
{
	public function __construct(
		private OAuthDiscoveryProvider $provider,
		private EditionFeatureService $editionFeatures,
		private ResponseFactoryInterface $responseFactory,
		private StreamFactoryInterface $streamFactory,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		if (!$this->editionFeatures->can(EditionFeature::OAUTH_SERVER)) {
			return $response->withStatus(404);
		}

		$handler = new ProtectedResourceMetadataHandler(
			$this->provider->protectedResourceMetadata(),
			$this->responseFactory,
			$this->streamFactory,
		);

		return $handler->handle($request);
	}
}
