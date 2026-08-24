<?php

declare(strict_types=1);

namespace Tests\Unit\Action\OAuth;

use PHPUnit\Framework\TestCase;
use Slim\Interfaces\RouteInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Routing\RouteContext;
use TotalCMS\Action\OAuth\OAuthDiscoveryAction;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\OAuth\Service\OAuthDiscoveryProvider;
use TotalCMS\Domain\OAuth\Service\OAuthScopeRegistry;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\BasePath;
use TotalCMS\Support\Config;

/**
 * A subfolder (Stacks) install with the setup wizard's optional root catch-all
 * rewrite, and NO `api` pinned in config — the default state for anyone who
 * followed the wizard.
 *
 * Such a site answers on two base paths. The property that matters is not that
 * they agree with each other, but that each one is INTERNALLY consistent: the
 * issuer a client is handed by the protected-resource document must be the
 * same issuer it gets back when it looks that issuer's metadata up. A client
 * never mixes shapes on its own, so two self-consistent authorities both work
 * and no operator configuration is required.
 *
 * That property used to break in one specific place. For an issuer with a path
 * component, RFC 8414 §3.1 puts the metadata at
 * `https://host/.well-known/oauth-authorization-server/<issuer-path>` — which
 * arrives at the DOMAIN ROOT. `config->api` is derived per request by
 * cross-checking SCRIPT_NAME against the request path, and a root-shaped
 * request has no subpath to match, so it resolved empty and the document came
 * back claiming the bare-host issuer. The client rejected the mismatch, which
 * presented as OAuth completing, a grant appearing in the admin, and then
 * every MCP call returning 401.
 *
 * The catch-all is an internal Apache rewrite to `{publicPrefix}/index.php`
 * (see ServerConfigAdvisor::apacheRewrite), so SCRIPT_NAME is identical for
 * both shapes — which is what lets the action recognise the queried base path.
 */
final class SubfolderDiscoveryConsistencyTest extends TestCase
{
	private const HOST   = 'https://demo.example.com';
	private const SCRIPT = '/rw_common/plugins/stacks/tcms/public/index.php';
	private const SUB    = '/rw_common/plugins/stacks/tcms';

	/** Mirrors how config/defaults.php derives `api` for a given request. */
	private function configFor(string $requestPath): Config
	{
		$config = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty($config, 'url'))->setValue($config, self::HOST);
		(new \ReflectionProperty($config, 'api'))->setValue($config, BasePath::resolve(self::SCRIPT, $requestPath));
		(new \ReflectionProperty($config, 'oauth'))->setValue($config, ['dynamicRegistration' => true]);

		return $config;
	}

	/** The issuer the protected-resource document points a client at. */
	private function advertisedAuthorizationServer(string $requestPath): string
	{
		$provider = new OAuthDiscoveryProvider($this->configFor($requestPath), new OAuthScopeRegistry());

		return (string)$provider->protectedResourceMetadata()->jsonSerialize()['authorization_servers'][0];
	}

	/**
	 * @return array{status:int,doc:array<string,mixed>}
	 */
	private function fetchMetadataAt(string $requestPath, ?string $routeArgument): array
	{
		$config  = $this->configFor($requestPath);
		$edition = $this->createMock(EditionFeatureService::class);
		$edition->method('can')->willReturn(true);

		$action = new OAuthDiscoveryAction(
			new OAuthDiscoveryProvider($config, new OAuthScopeRegistry()),
			new JsonRenderer(),
			$edition,
			$config,
		);

		$request = (new ServerRequestFactory())
			->createServerRequest('GET', $requestPath, ['SCRIPT_NAME' => self::SCRIPT]);

		if ($routeArgument !== null) {
			$route = $this->createMock(RouteInterface::class);
			$route->method('getArgument')->willReturnCallback(
				fn (string $name): ?string => $name === 'path' ? $routeArgument : null,
			);
			$request = $request->withAttribute(RouteContext::ROUTE, $route);
		}

		$response = $action($request, new Response());

		/** @var array<string,mixed> $doc */
		$doc = json_decode((string)$response->getBody(), true) ?: [];

		return ['status' => $response->getStatusCode(), 'doc' => $doc];
	}

	public function testSubpathShapeResolvesToOneIssuerEndToEnd(): void
	{
		$issuer = $this->advertisedAuthorizationServer(self::SUB . '/.well-known/oauth-protected-resource');
		$this->assertSame(self::HOST . self::SUB, $issuer, 'subpath request must advertise the subpath issuer');

		// The §3.1 lookup for that issuer lands at the domain root.
		$result = $this->fetchMetadataAt(
			'/.well-known/oauth-authorization-server' . self::SUB,
			ltrim(self::SUB, '/'),
		);

		$this->assertSame(200, $result['status']);
		$this->assertSame($issuer, $result['doc']['issuer'], 'issuer must echo the one the client is verifying');
		$this->assertSame($issuer . '/oauth/token', $result['doc']['token_endpoint']);
		$this->assertSame($issuer . '/oauth/authorize', $result['doc']['authorization_endpoint']);
		$this->assertSame($issuer . '/oauth/register', $result['doc']['registration_endpoint']);
	}

	public function testRootShapeResolvesToOneIssuerEndToEnd(): void
	{
		$issuer = $this->advertisedAuthorizationServer('/.well-known/oauth-protected-resource');
		$this->assertSame(self::HOST, $issuer, 'root-shaped request must advertise the bare-host issuer');

		// A bare-host issuer has no path component, so §3.1 never applies —
		// the client queries the well-known root directly.
		$result = $this->fetchMetadataAt('/.well-known/oauth-authorization-server', null);

		$this->assertSame(200, $result['status']);
		$this->assertSame($issuer, $result['doc']['issuer']);
		$this->assertSame($issuer . '/oauth/token', $result['doc']['token_endpoint']);
	}
}
