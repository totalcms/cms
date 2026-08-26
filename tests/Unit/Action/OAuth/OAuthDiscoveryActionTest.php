<?php

declare(strict_types=1);

namespace Tests\Unit\Action\OAuth;

use PHPUnit\Framework\TestCase;
use Slim\Interfaces\RouteInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Routing\RouteContext;
use TotalCMS\Action\OAuth\OAuthDiscoveryAction;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\OAuth\Service\OAuthDiscoveryProvider;
use TotalCMS\Domain\OAuth\Service\OAuthScopeRegistry;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

/**
 * OAuthDiscoveryProvider is final — use a real instance backed by a Reflection-
 * constructed Config (same pattern as OAuthJwksActionTest::makeConfig).
 */
final class OAuthDiscoveryActionTest extends TestCase
{
	/** @param array<string,mixed> $oauth */
	private function makeConfig(string $api = '', array $oauth = ['jwtIssuer' => 'https://example.com', 'dynamicRegistration' => true]): Config
	{
		$config = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty($config, 'url'))->setValue($config, 'https://example.com');
		(new \ReflectionProperty($config, 'api'))->setValue($config, $api);
		(new \ReflectionProperty($config, 'oauth'))->setValue($config, $oauth);

		return $config;
	}

	private function makeEditionService(bool $allowsOAuth = true): EditionFeatureService
	{
		$editionFeatures = $this->createMock(EditionFeatureService::class);
		$editionFeatures->method('can')->willReturnCallback(
			fn (EditionFeature $f): bool => $f === EditionFeature::OAUTH_SERVER ? $allowsOAuth : true,
		);

		return $editionFeatures;
	}

	private function makeAction(bool $editionAllowsOAuth = true, ?Config $config = null): OAuthDiscoveryAction
	{
		$config ??= $this->makeConfig();
		$provider = new OAuthDiscoveryProvider($config, new OAuthScopeRegistry());

		return new OAuthDiscoveryAction(
			$provider,
			new JsonRenderer(),
			$this->makeEditionService($editionAllowsOAuth),
			$config,
		);
	}

	/**
	 * `$suffixPath` populates the {path} route argument the RFC 8414 §3.1
	 * route carries; null models the bare route. `$scriptName` is what the
	 * front controller reports for the layout under test.
	 */
	private function invoke(OAuthDiscoveryAction $action, ?string $suffixPath = null, string $scriptName = '/index.php'): Response
	{
		$request = (new ServerRequestFactory())
			->createServerRequest('GET', '/.well-known/oauth-authorization-server', ['SCRIPT_NAME' => $scriptName]);

		if ($suffixPath !== null) {
			$route = $this->createMock(RouteInterface::class);
			$route->method('getArgument')->willReturnCallback(
				fn (string $name): ?string => $name === 'path' ? $suffixPath : null,
			);
			$request = $request->withAttribute(RouteContext::ROUTE, $route);
		}

		/** @var Response $result */
		$result = $action($request, new Response());

		return $result;
	}

	public function testReturnsDiscoveryMetadataAsJsonWhenEditionAllowsOAuth(): void
	{
		$action = $this->makeAction(editionAllowsOAuth: true);
		$result = $this->invoke($action);

		$this->assertSame(200, $result->getStatusCode());

		$body = (string)$result->getBody();
		/** @var array<string,mixed>|null $data */
		$data = json_decode($body, true);

		$this->assertIsArray($data);
		$this->assertArrayHasKey('issuer', $data);
		$this->assertArrayHasKey('authorization_endpoint', $data);
		$this->assertArrayHasKey('token_endpoint', $data);
		$this->assertSame('https://example.com', $data['issuer']);
	}

	/**
	 * The case this whole branch exists for. A subfolder install with the
	 * optional root catch-all rewrite receives the RFC 8414 §3.1 metadata URL
	 * at the DOMAIN ROOT — `/.well-known/oauth-authorization-server/rw_common/...`
	 * — so the request path carries no subpath, `config->api` resolves empty,
	 * and the default issuer would come back as the bare host. The client
	 * discovered the subpath issuer from the protected-resource document and
	 * rejects the mismatch, which is a connector that finishes OAuth and then
	 * can't call a single tool.
	 */
	public function testPathSuffixedFormEchoesTheQueriedIssuerOnAnUnpinnedSubfolderInstall(): void
	{
		// api: '' models the root-shaped request — nothing pinned in config.
		$action = $this->makeAction(config: $this->makeConfig(api: '', oauth: []));

		$result = $this->invoke(
			$action,
			suffixPath: 'rw_common/plugins/stacks/tcms',
			scriptName: '/rw_common/plugins/stacks/tcms/public/index.php',
		);

		$this->assertSame(200, $result->getStatusCode());

		/** @var array<string,mixed> $data */
		$data = json_decode((string)$result->getBody(), true);

		$this->assertSame('https://example.com/rw_common/plugins/stacks/tcms', $data['issuer']);
		$this->assertSame('https://example.com/rw_common/plugins/stacks/tcms/oauth/authorize', $data['authorization_endpoint']);
		$this->assertSame('https://example.com/rw_common/plugins/stacks/tcms/oauth/token', $data['token_endpoint']);
		$this->assertSame('https://example.com/rw_common/plugins/stacks/tcms/.well-known/jwks.json', $data['jwks_uri']);
	}

	/**
	 * Every endpoint is built by concatenation onto the issuer, so echoing an
	 * unvalidated path would publish an authorization_endpoint pointing
	 * anywhere on the host the caller names. An unrecognised path therefore
	 * falls back to this install's own document — the caller's path must not
	 * appear anywhere in the response.
	 */
	public function testPathSuffixedFormNeverReflectsABasePathTheInstallDoesNotServe(): void
	{
		$action = $this->makeAction(config: $this->makeConfig(api: '', oauth: []));

		$result = $this->invoke(
			$action,
			suffixPath: 'attacker/controlled',
			scriptName: '/rw_common/plugins/stacks/tcms/public/index.php',
		);

		$this->assertSame(200, $result->getStatusCode());

		$body = (string)$result->getBody();
		$this->assertStringNotContainsString('attacker', $body);

		/** @var array<string,mixed> $data */
		$data = json_decode($body, true);
		$this->assertSame('https://example.com', $data['issuer']);
		$this->assertSame('https://example.com/oauth/authorize', $data['authorization_endpoint']);
	}

	public function testPathSuffixedFormAcceptsThePinnedApiPrefix(): void
	{
		// Pinned in config but SCRIPT_NAME reports something else entirely
		// (reverse proxy) — the pin is still a legitimate answer.
		$action = $this->makeAction(config: $this->makeConfig(api: '/cms', oauth: []));

		$result = $this->invoke($action, suffixPath: 'cms', scriptName: '/index.php');

		$this->assertSame(200, $result->getStatusCode());

		/** @var array<string,mixed> $data */
		$data = json_decode((string)$result->getBody(), true);
		$this->assertSame('https://example.com/cms', $data['issuer']);
	}

	/**
	 * An explicitly configured issuer is the only one the server has, so it is
	 * the only path form that can be answered — resolveIssuer() returns it
	 * unconditionally for every other route.
	 */
	public function testPathSuffixedFormHonoursAnExplicitJwtIssuer(): void
	{
		$config = $this->makeConfig(api: '', oauth: ['jwtIssuer' => 'https://example.com/pinned']);

		$match = $this->invoke($this->makeAction(config: $config), suffixPath: 'pinned');
		$this->assertSame(200, $match->getStatusCode());

		/** @var array<string,mixed> $data */
		$data = json_decode((string)$match->getBody(), true);
		$this->assertSame('https://example.com/pinned', $data['issuer']);

		// SCRIPT_NAME candidates must not widen an explicit issuer — the
		// configured one is still what comes back.
		$other = $this->invoke(
			$this->makeAction(config: $config),
			suffixPath: 'cms',
			scriptName: '/cms/index.php',
		);
		$this->assertSame(200, $other->getStatusCode());

		/** @var array<string,mixed> $otherData */
		$otherData = json_decode((string)$other->getBody(), true);
		$this->assertSame('https://example.com/pinned', $otherData['issuer']);
	}

	/**
	 * A root install's issuer has no path component, so §3.1 never applies —
	 * but clients ask anyway, appending the protected resource path
	 * (`/.well-known/oauth-authorization-server/mcp`) instead of the issuer
	 * path. Answering that with a 404 made Claude Desktop report the server as
	 * having no OAuth at all, so it must return the install's own document.
	 */
	public function testPathSuffixedFormServesTheInstallDocumentOnARootInstall(): void
	{
		$action = $this->makeAction(config: $this->makeConfig(api: '', oauth: []));

		$result = $this->invoke($action, suffixPath: 'mcp', scriptName: '/index.php');

		$this->assertSame(200, $result->getStatusCode());

		/** @var array<string,mixed> $data */
		$data = json_decode((string)$result->getBody(), true);
		$this->assertSame('https://example.com', $data['issuer']);
		$this->assertSame('https://example.com/oauth/token', $data['token_endpoint']);
	}

	public function testReturns404WhenEditionDoesNotAllowOAuth(): void
	{
		$action = $this->makeAction(editionAllowsOAuth: false);
		$result = $this->invoke($action);

		$this->assertSame(404, $result->getStatusCode());

		// Response should have no JSON body (raw withStatus, no renderer involved)
		$body = trim((string)$result->getBody());
		$this->assertSame('', $body);
	}
}
