<?php

declare(strict_types=1);

namespace Tests\Unit\Action\OAuth;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
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
	private function makeConfig(): Config
	{
		$config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty($config, 'url'))->setValue($config, 'https://example.com');
		(new ReflectionProperty($config, 'oauth'))->setValue($config, [
			'jwtIssuer'         => 'https://example.com',
			'dynamicRegistration' => true,
		]);
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

	private function makeAction(bool $editionAllowsOAuth = true): OAuthDiscoveryAction
	{
		$config   = $this->makeConfig();
		$provider = new OAuthDiscoveryProvider($config, new OAuthScopeRegistry());

		return new OAuthDiscoveryAction(
			$provider,
			new JsonRenderer(),
			$this->makeEditionService($editionAllowsOAuth),
		);
	}

	private function invoke(OAuthDiscoveryAction $action): Response
	{
		$request  = (new ServerRequestFactory())->createServerRequest('GET', '/.well-known/oauth-authorization-server');
		$response = new Response();
		/** @var Response $result */
		$result = $action($request, $response);
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
