<?php

declare(strict_types=1);

namespace TotalCMS\Action\OAuth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\OAuth\Service\OAuthDiscoveryProvider;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\BasePath;
use TotalCMS\Support\Config;

readonly class OAuthDiscoveryAction
{
	public function __construct(
		private OAuthDiscoveryProvider $provider,
		private JsonRenderer $renderer,
		private EditionFeatureService $editionFeatures,
		private Config $config,
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

		// RFC 8414 §3.1 path-suffixed form: the {path} route argument is present
		// only on /.well-known/oauth-authorization-server/{path:.*}. The client
		// is asking about a specific issuer and will verify the returned
		// `issuer` echoes it, so answer for that issuer rather than for
		// whatever base path this particular request happens to resolve to.
		//
		// That distinction is the whole point on a subfolder install with the
		// root catch-all rewrite: this URL arrives at the domain root, so
		// `config->api` resolves empty and the default issuer would come back
		// as the bare host — a mismatch against the subpath issuer the client
		// discovered from the protected-resource document, which strict
		// clients (Claude's connectors among them) reject outright.
		//
		// Read the route attribute directly rather than via
		// RouteContext::fromRequest(), which throws outright when routing
		// hasn't run — the bare route must keep working regardless.
		$route = $request->getAttribute(RouteContext::ROUTE);
		$path  = $route instanceof RouteInterface ? (string)($route->getArgument('path') ?? '') : '';
		if (trim($path, '/') === '') {
			return $this->renderer->json($response, $this->provider->metadata());
		}

		// A path we don't recognise falls back to this install's own document.
		// Clients do not all derive this URL from the issuer as §3.1 specifies —
		// some append the protected RESOURCE path instead, so a root install
		// whose issuer has no path component still gets asked for
		// `/.well-known/oauth-authorization-server/mcp`. 404ing that made such a
		// client conclude the server has no OAuth at all. Falling back reflects
		// nothing back to the caller — the document describes this install, the
		// same as the bare route — so it costs nothing to be lenient here.
		return $this->renderer->json(
			$response,
			$this->provider->metadata($this->issuerForPath($request, '/' . trim($path, '/'))),
		);
	}

	/**
	 * Build the issuer for a queried base path, or null when this install does
	 * not answer at that path — in which case the caller serves its own
	 * document rather than anything derived from the query.
	 *
	 * Validation is not optional: every endpoint in the metadata document is
	 * built by concatenation onto the issuer, so echoing an arbitrary path
	 * back would publish an `authorization_endpoint` pointing at any location
	 * on this host the caller names. Returning null is what keeps an unknown
	 * path out of the response entirely.
	 *
	 * Scheme and host come from `config->url`, never the inbound Host header,
	 * matching how OAuthDiscoveryProvider builds the default issuer.
	 */
	private function issuerForPath(ServerRequestInterface $request, string $prefix): ?string
	{
		$base = rtrim($this->config->url, '/');

		// An explicitly configured issuer is the only one this server has —
		// OAuthDiscoveryProvider::resolveIssuer() returns it unconditionally,
		// so nothing else can be a legitimate answer here either.
		$configured = rtrim((string)($this->config->oauth['jwtIssuer'] ?? ''), '/');
		if ($configured !== '') {
			return $configured === $base . $prefix ? $configured : null;
		}

		// Otherwise: the pinned mount prefix, plus the prefixes SCRIPT_NAME
		// says this front controller is genuinely reachable at. Nothing a
		// request can invent, and it covers the unpinned subfolder install
		// whose own `config->api` reads empty on a root-shaped request.
		$allowed = BasePath::candidates((string)($request->getServerParams()['SCRIPT_NAME'] ?? ''));

		$pinned = rtrim($this->config->api, '/');
		if ($pinned !== '') {
			$allowed[] = $pinned;
		}

		return in_array($prefix, $allowed, true) ? $base . $prefix : null;
	}
}
