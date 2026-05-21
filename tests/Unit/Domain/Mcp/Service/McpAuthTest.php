<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Service;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Service\ApiKeyAuthenticator;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Exception\McpAuthException;
use TotalCMS\Domain\Mcp\Service\McpAuth;
use TotalCMS\Support\Config;

final class McpAuthTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $authenticator;
	private Config $config;

	protected function setUp(): void
	{
		$this->authenticator = $this->createMock(ApiKeyAuthenticator::class);

		// Config can't be createMock'd reliably because its constructor expects
		// a fully-populated settings array; bypass it.
		$this->config      = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$this->config->mcp = ['publicAccess' => true];
	}

	private function auth(): McpAuth
	{
		return new McpAuth($this->authenticator, $this->config);
	}

	private function makeApiKey(): ApiKeyData
	{
		return new ApiKeyData([
			'id'      => 'k1',
			'name'    => 'Test',
			'key'     => 'tcms_valid',
			'created' => '2025-01-01T00:00:00Z',
			'scopes'  => ['methods' => ['POST'], 'paths' => ['/mcp']],
		]);
	}

	public function testNoKeyAndPublicAccessOnReturnsPublicPersona(): void
	{
		$this->config->mcp = ['publicAccess' => true];
		$request           = $this->createMock(ServerRequestInterface::class);
		$this->authenticator->method('hasApiKeyHeader')->willReturn(false);

		$persona = $this->auth()->resolvePersona($request);

		$this->assertSame(McpPersona::PUBLIC_, $persona);
	}

	public function testNoKeyAndPublicAccessOffThrows(): void
	{
		$this->config->mcp = ['publicAccess' => false];
		$request           = $this->createMock(ServerRequestInterface::class);
		$this->authenticator->method('hasApiKeyHeader')->willReturn(false);

		$this->expectException(McpAuthException::class);
		$this->expectExceptionMessage('Anonymous access is disabled');

		$this->auth()->resolvePersona($request);
	}

	public function testNoKeyAndPublicAccessMissingDefaultsToDeny(): void
	{
		// Defensive: an MCP block missing publicAccess entirely should deny,
		// not silently allow public.
		$this->config->mcp = [];
		$request           = $this->createMock(ServerRequestInterface::class);
		$this->authenticator->method('hasApiKeyHeader')->willReturn(false);

		$this->expectException(McpAuthException::class);

		$this->auth()->resolvePersona($request);
	}

	public function testValidKeyWithMcpScopeResolvesToAdmin(): void
	{
		// authenticate() returns ApiKeyData when the key is valid AND its
		// scopes cover the actual request method + path. McpAuth treats that
		// as proof of admin authority — no further checks.
		$request = $this->createMock(ServerRequestInterface::class);
		$this->authenticator->method('hasApiKeyHeader')->willReturn(true);
		$this->authenticator->method('authenticate')->with($request)->willReturn($this->makeApiKey());

		$persona = $this->auth()->resolvePersona($request);

		$this->assertSame(McpPersona::ADMIN, $persona);
	}

	public function testInvalidKeyThrows(): void
	{
		// authenticate() returns null for both "key doesn't exist" and "valid
		// key without /mcp scope". McpAuth collapses both into one error since
		// they're functionally identical for the caller.
		$request = $this->createMock(ServerRequestInterface::class);
		$this->authenticator->method('hasApiKeyHeader')->willReturn(true);
		$this->authenticator->method('authenticate')->with($request)->willReturn(null);

		$this->expectException(McpAuthException::class);
		$this->expectExceptionMessage('insufficient permissions for MCP access');

		$this->auth()->resolvePersona($request);
	}

	public function testKeyWithoutMcpScopeIsRejected(): void
	{
		// A key scoped only to /collections/blog should not unlock MCP —
		// expressed via authenticate() returning null because the scope check
		// fails inside the standard pipeline.
		$request = $this->createMock(ServerRequestInterface::class);
		$this->authenticator->method('hasApiKeyHeader')->willReturn(true);
		$this->authenticator->method('authenticate')->with($request)->willReturn(null);

		$this->expectException(McpAuthException::class);

		$this->auth()->resolvePersona($request);
	}

	public function testNoKeyAndPublicAccessOffCarriesLoginRequiredReason(): void
	{
		// The exception carries a `reason` so the endpoint action can emit the
		// correct WWW-Authenticate header: login_required for absent
		// credentials vs invalid_token for bad credentials. Lazy-auth UX in
		// Claude relies on the distinction.
		$this->config->mcp = ['publicAccess' => false];
		$request           = $this->createMock(ServerRequestInterface::class);
		$this->authenticator->method('hasApiKeyHeader')->willReturn(false);

		try {
			$this->auth()->resolvePersona($request);
			$this->fail('Expected McpAuthException.');
		} catch (McpAuthException $e) {
			$this->assertSame('login_required', $e->reason);
		}
	}

	public function testInvalidKeyCarriesInvalidTokenReason(): void
	{
		$request = $this->createMock(ServerRequestInterface::class);
		$this->authenticator->method('hasApiKeyHeader')->willReturn(true);
		$this->authenticator->method('authenticate')->with($request)->willReturn(null);

		try {
			$this->auth()->resolvePersona($request);
			$this->fail('Expected McpAuthException.');
		} catch (McpAuthException $e) {
			$this->assertSame('invalid_token', $e->reason);
		}
	}

	public function testValidKeyBypassesPublicAccessFlag(): void
	{
		// Even with publicAccess off, a valid key still authenticates as admin.
		$this->config->mcp = ['publicAccess' => false];
		$request           = $this->createMock(ServerRequestInterface::class);
		$this->authenticator->method('hasApiKeyHeader')->willReturn(true);
		$this->authenticator->method('authenticate')->with($request)->willReturn($this->makeApiKey());

		$persona = $this->auth()->resolvePersona($request);

		$this->assertSame(McpPersona::ADMIN, $persona);
	}
}
