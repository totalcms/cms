<?php

declare(strict_types=1);

namespace TotalCMS\Action\OAuth;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Slim\Routing\RouteContext;
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
 * The document comes from OAuthDiscoveryProvider; the edition gate mirrors
 * OAuthDiscoveryAction's non-Pro behavior: 404 when the OAuth server feature
 * is unavailable.
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

		// RFC 9728 §3 path-suffixed form: the {path} route argument is present
		// only on /.well-known/oauth-protected-resource/{path:.*}. Clients that
		// hit this form are asking about a specific resource identifier and
		// verify the returned `resource` echoes what they queried — the bare
		// route's default (T3's API root) would fail that check.
		$path     = RouteContext::fromRequest($request)->getRoute()?->getArgument('path') ?? '';
		$resource = null;
		if ($path !== '') {
			$uri      = $request->getUri();
			$resource = rtrim($uri->getScheme() . '://' . $uri->getAuthority(), '/') . '/' . ltrim($path, '/');
		}

		$json = json_encode($this->provider->protectedResourceMetadata($resource), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

		return $this->responseFactory
			->createResponse(200)
			->withHeader('Content-Type', 'application/json')
			->withBody($this->streamFactory->createStream($json));
	}
}
