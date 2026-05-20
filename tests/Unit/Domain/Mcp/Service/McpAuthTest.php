<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Service;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Repository\ApiKeyRepository;
use TotalCMS\Domain\ApiKey\Service\ApiKeyAuthenticator;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Exception\McpAuthException;
use TotalCMS\Domain\Mcp\Service\McpAuth;
use TotalCMS\Support\Config;

final class McpAuthTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $authenticator;
	private \PHPUnit\Framework\MockObject\MockObject $repository;
	private Config $config;

	protected function setUp(): void
	{
		$this->authenticator = $this->createMock(ApiKeyAuthenticator::class);
		$this->repository    = $this->createMock(ApiKeyRepository::class);

		// Config can't be createMock'd reliably because its constructor expects
		// a fully-populated settings array; bypass it.
		$this->config      = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$this->config->mcp = ['publicAccess' => true];
	}

	private function auth(): McpAuth
	{
		return new McpAuth($this->authenticator, $this->repository, $this->config);
	}

	private function requestWithoutKey(): ServerRequestInterface
	{
		$request = $this->createMock(ServerRequestInterface::class);
		$this->authenticator->method('hasApiKeyHeader')->with($request)->willReturn(false);

		return $request;
	}

	private function requestWithBearer(string $token): ServerRequestInterface
	{
		$request = $this->createMock(ServerRequestInterface::class);
		$this->authenticator->method('hasApiKeyHeader')->with($request)->willReturn(true);
		$request->method('getHeaderLine')->with('Authorization')->willReturn('Bearer ' . $token);

		return $request;
	}

	private function requestWithXApiKey(string $key): ServerRequestInterface
	{
		$request = $this->createMock(ServerRequestInterface::class);
		$this->authenticator->method('hasApiKeyHeader')->with($request)->willReturn(true);
		$request->method('getHeaderLine')->willReturnMap([
			['Authorization', ''],
			['X-API-Key', $key],
		]);
		$request->method('hasHeader')->with('X-API-Key')->willReturn(true);

		return $request;
	}

	public function testNoKeyAndPublicAccessOnReturnsPublicPersona(): void
	{
		$this->config->mcp = ['publicAccess' => true];

		$persona = $this->auth()->resolvePersona($this->requestWithoutKey());

		$this->assertSame(McpPersona::PUBLIC_, $persona);
	}

	public function testNoKeyAndPublicAccessOffThrows(): void
	{
		$this->config->mcp = ['publicAccess' => false];

		$this->expectException(McpAuthException::class);
		$this->expectExceptionMessage('Anonymous access is disabled');

		$this->auth()->resolvePersona($this->requestWithoutKey());
	}

	public function testNoKeyAndPublicAccessMissingDefaultsToDeny(): void
	{
		// Defensive: an MCP block missing publicAccess entirely should deny,
		// not silently allow public.
		$this->config->mcp = [];

		$this->expectException(McpAuthException::class);

		$this->auth()->resolvePersona($this->requestWithoutKey());
	}

	public function testValidBearerTokenResolvesToAdmin(): void
	{
		$apiKey = new ApiKeyData([
			'id'      => 'k1',
			'name'    => 'Test',
			'key'     => 'tcms_valid',
			'created' => '2025-01-01T00:00:00Z',
			'scopes'  => ['methods' => [], 'paths' => []],
		]);
		$this->repository->method('findByKey')->with('tcms_valid')->willReturn($apiKey);

		$persona = $this->auth()->resolvePersona($this->requestWithBearer('tcms_valid'));

		$this->assertSame(McpPersona::ADMIN, $persona);
	}

	public function testValidXApiKeyResolvesToAdmin(): void
	{
		$apiKey = new ApiKeyData([
			'id'      => 'k2',
			'name'    => 'Test2',
			'key'     => 'tcms_xkey',
			'created' => '2025-01-01T00:00:00Z',
			'scopes'  => ['methods' => [], 'paths' => []],
		]);
		$this->repository->method('findByKey')->with('tcms_xkey')->willReturn($apiKey);

		$persona = $this->auth()->resolvePersona($this->requestWithXApiKey('tcms_xkey'));

		$this->assertSame(McpPersona::ADMIN, $persona);
	}

	public function testInvalidKeyThrows(): void
	{
		$this->repository->method('findByKey')->willReturn(null);

		$this->expectException(McpAuthException::class);
		$this->expectExceptionMessage('Invalid API key');

		$this->auth()->resolvePersona($this->requestWithBearer('tcms_bogus'));
	}

	public function testEmptyBearerTokenThrows(): void
	{
		// "Bearer " with nothing after — has the header, has no actual key.
		$request = $this->createMock(ServerRequestInterface::class);
		$this->authenticator->method('hasApiKeyHeader')->with($request)->willReturn(true);
		$request->method('getHeaderLine')->willReturnMap([
			['Authorization', ''],
			['X-API-Key', ''],
		]);
		$request->method('hasHeader')->with('X-API-Key')->willReturn(true);

		$this->expectException(McpAuthException::class);
		$this->expectExceptionMessage('Empty API key supplied');

		$this->auth()->resolvePersona($request);
	}

	public function testValidKeyBypassesPublicAccessFlag(): void
	{
		// Even with publicAccess off, a valid key still authenticates.
		$this->config->mcp = ['publicAccess' => false];
		$apiKey            = new ApiKeyData([
			'id'      => 'k3',
			'name'    => 'Test3',
			'key'     => 'tcms_admin',
			'created' => '2025-01-01T00:00:00Z',
			'scopes'  => ['methods' => [], 'paths' => []],
		]);
		$this->repository->method('findByKey')->willReturn($apiKey);

		$persona = $this->auth()->resolvePersona($this->requestWithBearer('tcms_admin'));

		$this->assertSame(McpPersona::ADMIN, $persona);
	}
}
