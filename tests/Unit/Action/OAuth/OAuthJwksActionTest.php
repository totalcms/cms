<?php

declare(strict_types=1);

namespace Tests\Unit\Action\OAuth;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use TotalCMS\Action\OAuth\OAuthJwksAction;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

final class OAuthJwksActionTest extends TestCase
{
	private string $tmpDir;
	private string $publicKeyPath;

	protected function setUp(): void
	{
		$this->tmpDir = sys_get_temp_dir() . '/oauth-jwks-test-' . uniqid();
		mkdir($this->tmpDir, 0700, true);

		$resource = openssl_pkey_new([
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		]);
		$this->assertNotFalse($resource, 'openssl_pkey_new() failed');

		$details = openssl_pkey_get_details($resource);
		$this->assertIsArray($details);

		$this->publicKeyPath = $this->tmpDir . '/public.key';
		file_put_contents($this->publicKeyPath, (string)$details['key']);
	}

	protected function tearDown(): void
	{
		@unlink($this->publicKeyPath);
		@rmdir($this->tmpDir);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function makeConfig(string $publicKeyPath): Config
	{
		$config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty($config, 'oauth'))->setValue($config, [
			'publicKeyPath' => $publicKeyPath,
		]);
		return $config;
	}

	private function makeAction(string $publicKeyPath, bool $editionAllowsOAuth = true): OAuthJwksAction
	{
		return new OAuthJwksAction(
			$this->makeConfig($publicKeyPath),
			new JsonRenderer(),
			$this->makeEditionService($editionAllowsOAuth),
		);
	}

	private function makeEditionService(bool $allowsOAuth = true): EditionFeatureService
	{
		$editionFeatures = $this->createMock(EditionFeatureService::class);
		$editionFeatures->method('can')->willReturnCallback(
			fn (EditionFeature $f): bool => $f === EditionFeature::OAUTH_SERVER ? $allowsOAuth : true,
		);
		return $editionFeatures;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function invokeAndDecode(OAuthJwksAction $action): ?array
	{
		$request  = (new ServerRequestFactory())->createServerRequest('GET', '/.well-known/jwks.json');
		$response = new Response();
		$result   = $action($request, $response);
		$body     = (string)$result->getBody();
		/** @var array<string,mixed>|null $decoded */
		$decoded = json_decode($body, true);
		return $decoded;
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	public function testReturnsJwksWithSingleKeyWhenValidPublicKeyPresent(): void
	{
		$action = $this->makeAction($this->publicKeyPath);
		$data   = $this->invokeAndDecode($action);

		$this->assertIsArray($data);
		$this->assertArrayHasKey('keys', $data);
		$this->assertIsArray($data['keys']);
		$this->assertCount(1, $data['keys']);
	}

	public function testJwkHasAllRequiredFields(): void
	{
		$action = $this->makeAction($this->publicKeyPath);
		$data   = $this->invokeAndDecode($action);

		$this->assertIsArray($data);
		$jwk = $data['keys'][0];
		$this->assertIsArray($jwk);

		$this->assertSame('RSA', $jwk['kty']);
		$this->assertSame('sig', $jwk['use']);
		$this->assertSame('RS256', $jwk['alg']);
		$this->assertArrayHasKey('kid', $jwk);
		$this->assertArrayHasKey('n', $jwk);
		$this->assertArrayHasKey('e', $jwk);
	}

	public function testKidIsDeterministicForSameKey(): void
	{
		$action1 = $this->makeAction($this->publicKeyPath);
		$action2 = $this->makeAction($this->publicKeyPath);

		$data1 = $this->invokeAndDecode($action1);
		$data2 = $this->invokeAndDecode($action2);

		$this->assertIsArray($data1);
		$this->assertIsArray($data2);

		$kid1 = $data1['keys'][0]['kid'];
		$kid2 = $data2['keys'][0]['kid'];

		$this->assertSame($kid1, $kid2);
	}

	public function testKidIsFirst16HexCharsOfSha256(): void
	{
		$action = $this->makeAction($this->publicKeyPath);
		$data   = $this->invokeAndDecode($action);

		$this->assertIsArray($data);
		$kid = (string)$data['keys'][0]['kid'];

		// Must be exactly 16 hex characters
		$this->assertSame(16, strlen($kid));
		$this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $kid);
	}

	public function testDifferentKeyFilesProduceDifferentKids(): void
	{
		// Generate a second key
		$resource2 = openssl_pkey_new([
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		]);
		$this->assertNotFalse($resource2);
		$details2 = openssl_pkey_get_details($resource2);
		$this->assertIsArray($details2);

		$publicKeyPath2 = $this->tmpDir . '/public2.key';
		file_put_contents($publicKeyPath2, (string)$details2['key']);

		try {
			$action1 = $this->makeAction($this->publicKeyPath);
			$action2 = $this->makeAction($publicKeyPath2);

			$data1 = $this->invokeAndDecode($action1);
			$data2 = $this->invokeAndDecode($action2);

			$this->assertIsArray($data1);
			$this->assertIsArray($data2);

			$kid1 = $data1['keys'][0]['kid'];
			$kid2 = $data2['keys'][0]['kid'];

			$this->assertNotSame($kid1, $kid2);
		} finally {
			@unlink($publicKeyPath2);
		}
	}

	public function testNAndEAreBase64UrlWithoutPadding(): void
	{
		$action = $this->makeAction($this->publicKeyPath);
		$data   = $this->invokeAndDecode($action);

		$this->assertIsArray($data);
		$jwk = $data['keys'][0];

		// base64url must not contain +, /, or = characters
		$this->assertDoesNotMatchRegularExpression('/[+\/=]/', (string)$jwk['n']);
		$this->assertDoesNotMatchRegularExpression('/[+\/=]/', (string)$jwk['e']);

		// Must be non-empty
		$this->assertNotEmpty($jwk['n']);
		$this->assertNotEmpty($jwk['e']);
	}

	public function testReturns200StatusWithValidKey(): void
	{
		$action   = $this->makeAction($this->publicKeyPath);
		$request  = (new ServerRequestFactory())->createServerRequest('GET', '/.well-known/jwks.json');
		$response = new Response();
		$result   = $action($request, $response);

		$this->assertSame(200, $result->getStatusCode());
	}

	public function testReturnsEmptyKeysWithStatus503WhenKeyFileMissing(): void
	{
		$action  = $this->makeAction('/nonexistent/path/public.key');
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/.well-known/jwks.json');
		$result  = $action($request, new Response());

		$this->assertSame(503, $result->getStatusCode());

		$body = (string)$result->getBody();
		/** @var array<string,mixed>|null $data */
		$data = json_decode($body, true);
		$this->assertIsArray($data);
		$this->assertArrayHasKey('keys', $data);
		$this->assertSame([], $data['keys']);
	}

	public function testReturnsEmptyKeysWithStatus503WhenPublicKeyPathIsEmpty(): void
	{
		$config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty($config, 'oauth'))->setValue($config, [
			'publicKeyPath' => '',
		]);

		$action  = new OAuthJwksAction($config, new JsonRenderer(), $this->makeEditionService());
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/.well-known/jwks.json');
		$result  = $action($request, new Response());

		$this->assertSame(503, $result->getStatusCode());

		$body = (string)$result->getBody();
		/** @var array<string,mixed>|null $data */
		$data = json_decode($body, true);
		$this->assertIsArray($data);
		$this->assertSame([], $data['keys']);
	}

	public function testReturnsEmptyKeysWithStatus503WhenOauthConfigMissing(): void
	{
		$config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty($config, 'oauth'))->setValue($config, []);

		$action  = new OAuthJwksAction($config, new JsonRenderer(), $this->makeEditionService());
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/.well-known/jwks.json');
		$result  = $action($request, new Response());

		$this->assertSame(503, $result->getStatusCode());
	}

	public function testContentTypeIsJson(): void
	{
		$action  = $this->makeAction($this->publicKeyPath);
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/.well-known/jwks.json');
		$result  = $action($request, new Response());

		$this->assertStringContainsString('application/json', $result->getHeaderLine('Content-Type'));
	}

	public function testReturns404OnNonProEdition(): void
	{
		$action  = $this->makeAction($this->publicKeyPath, editionAllowsOAuth: false);
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/.well-known/jwks.json');
		$result  = $action($request, new Response());

		$this->assertSame(404, $result->getStatusCode());
	}
}
