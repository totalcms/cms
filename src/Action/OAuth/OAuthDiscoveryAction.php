<?php

declare(strict_types=1);

namespace TotalCMS\Action\OAuth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\OAuth\Service\OAuthDiscoveryProvider;
use TotalCMS\Renderer\JsonRenderer;

readonly class OAuthDiscoveryAction
{
	public function __construct(
		private OAuthDiscoveryProvider $provider,
		private JsonRenderer $renderer,
		private EditionFeatureService $editionFeatures,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		// On non-Pro instances, return 404 rather than the discovery payload.
		// OAuth clients expect a 404 here to mean "this server doesn't support
		// OAuth at all" — cleaner than an enabled:false signal that league
		// and most external clients don't actually consume.
		if (!$this->editionFeatures->can(EditionFeature::OAUTH_SERVER)) {
			return $response->withStatus(404);
		}

		return $this->renderer->json($response, $this->provider->metadata());
	}
}
