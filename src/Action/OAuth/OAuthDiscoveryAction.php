<?php

declare(strict_types=1);

namespace TotalCMS\Action\OAuth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\OAuth\Service\OAuthDiscoveryProvider;
use TotalCMS\Renderer\JsonRenderer;

readonly class OAuthDiscoveryAction
{
	public function __construct(
		private OAuthDiscoveryProvider $provider,
		private JsonRenderer $renderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		return $this->renderer->json($response, $this->provider->metadata());
	}
}
