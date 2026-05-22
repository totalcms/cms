<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Data\McpResourceDefinition;

final class McpResourceDefinitionTest extends TestCase
{
	private function resource(string $access, string $uri = 'tcms://blog/'): McpResourceDefinition
	{
		return new McpResourceDefinition(
			uri: $uri,
			name: 'Blog',
			description: 'desc',
			mimeType: 'application/json',
			access: $access,
			handler: static fn (): array => [],
		);
	}

	public function testConstructorAssignsAllProperties(): void
	{
		$handler = static fn (): array => ['contents' => []];
		$res     = new McpResourceDefinition(
			uri: 'tcms://products/',
			name: 'Products',
			description: 'Product catalog',
			mimeType: 'application/json',
			access: 'public',
			handler: $handler,
		);

		$this->assertSame('tcms://products/', $res->uri);
		$this->assertSame('Products', $res->name);
		$this->assertSame('Product catalog', $res->description);
		$this->assertSame('application/json', $res->mimeType);
		$this->assertSame('public', $res->access);
		$this->assertSame($handler, $res->handler);
	}

	public function testAdminPersonaSeesAdminResource(): void
	{
		$this->assertTrue($this->resource('admin')->isVisibleTo(McpPersona::ADMIN));
	}

	public function testAdminPersonaSeesPublicResource(): void
	{
		$this->assertTrue($this->resource('public')->isVisibleTo(McpPersona::ADMIN));
	}

	public function testAdminPersonaSeesAuthenticatedResource(): void
	{
		$this->assertTrue($this->resource('authenticated')->isVisibleTo(McpPersona::ADMIN));
	}

	public function testPublicPersonaCannotSeeAdminResource(): void
	{
		$this->assertFalse($this->resource('admin')->isVisibleTo(McpPersona::PUBLIC_));
	}

	public function testPublicPersonaSeesPublicResource(): void
	{
		$this->assertTrue($this->resource('public')->isVisibleTo(McpPersona::PUBLIC_));
	}

	public function testPublicPersonaCannotSeeAuthenticatedResource(): void
	{
		$this->assertFalse($this->resource('authenticated')->isVisibleTo(McpPersona::PUBLIC_));
	}

	public function testAuthenticatedPersonaCannotSeeAdminResource(): void
	{
		$this->assertFalse($this->resource('admin')->isVisibleTo(McpPersona::AUTHENTICATED));
	}

	public function testAuthenticatedPersonaSeesPublicResource(): void
	{
		$this->assertTrue($this->resource('public')->isVisibleTo(McpPersona::AUTHENTICATED));
	}

	public function testAuthenticatedPersonaSeesAuthenticatedResource(): void
	{
		$this->assertTrue($this->resource('authenticated')->isVisibleTo(McpPersona::AUTHENTICATED));
	}
}
