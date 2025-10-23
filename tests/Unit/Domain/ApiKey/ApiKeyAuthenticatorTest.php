<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ApiKey;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Service\ApiKeyAuthenticator;
use TotalCMS\Domain\ApiKey\Service\ApiKeyFetcher;
use TotalCMS\Support\Config;

final class ApiKeyAuthenticatorTest extends TestCase
{
	private ApiKeyAuthenticator $authenticator;
	private \PHPUnit\Framework\MockObject\MockObject $apiKeyFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $config;
	private \PHPUnit\Framework\MockObject\MockObject $request;

	protected function setUp(): void
	{
		$this->apiKeyFetcher = $this->createMock(ApiKeyFetcher::class);
		$this->config        = $this->createMock(Config::class);
		$this->request       = $this->createMock(ServerRequestInterface::class);

		// Mock the config->api property
		$this->config->api = 'https://demo.totalcms.test/rw_common/plugins/stacks/tcms';

		$this->authenticator = new ApiKeyAuthenticator(
			$this->apiKeyFetcher,
			$this->config
		);
	}

	public function testHasApiKeyHeaderReturnsFalseWhenNoHeaders(): void
	{
		$this->request->expects($this->once())
			->method('getHeaderLine')
			->with('Authorization')
			->willReturn('');

		$this->request->expects($this->once())
			->method('hasHeader')
			->with('X-API-Key')
			->willReturn(false);

		$result = $this->authenticator->hasApiKeyHeader($this->request);

		$this->assertFalse($result);
	}

	public function testHasApiKeyHeaderReturnsTrueForBearerToken(): void
	{
		$this->request->expects($this->once())
			->method('getHeaderLine')
			->with('Authorization')
			->willReturn('Bearer tcms_test123');

		$result = $this->authenticator->hasApiKeyHeader($this->request);

		$this->assertTrue($result);
	}

	public function testHasApiKeyHeaderReturnsTrueForXApiKey(): void
	{
		$this->request->expects($this->once())
			->method('getHeaderLine')
			->with('Authorization')
			->willReturn('');

		$this->request->expects($this->once())
			->method('hasHeader')
			->with('X-API-Key')
			->willReturn(true);

		$result = $this->authenticator->hasApiKeyHeader($this->request);

		$this->assertTrue($result);
	}

	public function testAuthenticateReturnsNullWhenNoApiKey(): void
	{
		$this->request->expects($this->once())
			->method('getHeaderLine')
			->with('Authorization')
			->willReturn('');

		$this->request->expects($this->once())
			->method('hasHeader')
			->with('X-API-Key')
			->willReturn(false);

		$result = $this->authenticator->authenticate($this->request);

		$this->assertNull($result);
	}

	public function testAuthenticateWithBearerToken(): void
	{
		$apiKey     = 'tcms_validkey123';
		$apiKeyData = new ApiKeyData([
			'id'      => 'key-123',
			'name'    => 'Test Key',
			'key'     => $apiKey,
			'created' => gmdate('Y-m-d\TH:i:s\Z'),
			'scopes'  => [
				'methods' => ['GET'],
				'paths'   => ['/collections/blog'],
			],
		]);

		$this->request->expects($this->once())
			->method('getHeaderLine')
			->with('Authorization')
			->willReturn('Bearer ' . $apiKey);

		$this->request->expects($this->once())
			->method('getMethod')
			->willReturn('GET');

		$uri = $this->createMock(UriInterface::class);
		$uri->expects($this->once())
			->method('getPath')
			->willReturn('/rw_common/plugins/stacks/tcms/collections/blog');

		$this->request->expects($this->once())
			->method('getUri')
			->willReturn($uri);

		$this->apiKeyFetcher->expects($this->once())
			->method('validateKey')
			->with($apiKey, 'GET', '/collections/blog')
			->willReturn($apiKeyData);

		$result = $this->authenticator->authenticate($this->request);

		$this->assertSame($apiKeyData, $result);
	}

	public function testAuthenticateWithXApiKeyHeader(): void
	{
		$apiKey     = 'tcms_validkey123';
		$apiKeyData = new ApiKeyData([
			'id'      => 'key-123',
			'name'    => 'Test Key',
			'key'     => $apiKey,
			'created' => gmdate('Y-m-d\TH:i:s\Z'),
			'scopes'  => [
				'methods' => ['GET'],
				'paths'   => ['*'],
			],
		]);

		// getHeaderLine called twice: once for Authorization, once for X-API-Key
		$this->request->expects($this->exactly(2))
			->method('getHeaderLine')
			->willReturnMap([
				['Authorization', ''],
				['X-API-Key', $apiKey],
			]);

		$this->request->expects($this->once())
			->method('hasHeader')
			->with('X-API-Key')
			->willReturn(true);

		$this->request->expects($this->once())
			->method('getMethod')
			->willReturn('GET');

		$uri = $this->createMock(UriInterface::class);
		$uri->expects($this->once())
			->method('getPath')
			->willReturn('/collections');

		$this->request->expects($this->once())
			->method('getUri')
			->willReturn($uri);

		$this->apiKeyFetcher->expects($this->once())
			->method('validateKey')
			->with($apiKey, 'GET', '/collections')
			->willReturn($apiKeyData);

		$result = $this->authenticator->authenticate($this->request);

		$this->assertSame($apiKeyData, $result);
	}

	public function testAuthenticateStripsBasePathFromChildRoute(): void
	{
		$apiKey     = 'tcms_validkey123';
		$apiKeyData = new ApiKeyData([
			'id'      => 'key-123',
			'name'    => 'Test Key',
			'key'     => $apiKey,
			'created' => gmdate('Y-m-d\TH:i:s\Z'),
			'scopes'  => [
				'methods' => ['GET'],
				'paths'   => ['/collections/text'],
			],
		]);

		$this->request->expects($this->once())
			->method('getHeaderLine')
			->with('Authorization')
			->willReturn('Bearer ' . $apiKey);

		$this->request->expects($this->once())
			->method('getMethod')
			->willReturn('GET');

		$uri = $this->createMock(UriInterface::class);
		$uri->expects($this->once())
			->method('getPath')
			->willReturn('/rw_common/plugins/stacks/tcms/collections/text/abc123');

		$this->request->expects($this->once())
			->method('getUri')
			->willReturn($uri);

		// Should strip base path and validate with /collections/text/abc123
		$this->apiKeyFetcher->expects($this->once())
			->method('validateKey')
			->with($apiKey, 'GET', '/collections/text/abc123')
			->willReturn($apiKeyData);

		$result = $this->authenticator->authenticate($this->request);

		$this->assertSame($apiKeyData, $result);
	}

	public function testAuthenticateReturnsNullWhenValidationFails(): void
	{
		$apiKey = 'tcms_invalidkey';

		$this->request->expects($this->once())
			->method('getHeaderLine')
			->with('Authorization')
			->willReturn('Bearer ' . $apiKey);

		$this->request->expects($this->once())
			->method('getMethod')
			->willReturn('GET');

		$uri = $this->createMock(UriInterface::class);
		$uri->expects($this->once())
			->method('getPath')
			->willReturn('/collections/blog');

		$this->request->expects($this->once())
			->method('getUri')
			->willReturn($uri);

		$this->apiKeyFetcher->expects($this->once())
			->method('validateKey')
			->with($apiKey, 'GET', '/collections/blog')
			->willReturn(null);

		$result = $this->authenticator->authenticate($this->request);

		$this->assertNull($result);
	}
}
