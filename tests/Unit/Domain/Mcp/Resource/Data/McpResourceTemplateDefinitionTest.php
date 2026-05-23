<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Resource\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Resource\Data\McpResourceTemplateDefinition;

final class McpResourceTemplateDefinitionTest extends TestCase
{
	private function template(string $access, string $uriTemplate = 'tcms://blog/{id}'): McpResourceTemplateDefinition
	{
		return new McpResourceTemplateDefinition(
			uriTemplate: $uriTemplate,
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
		$tmpl    = new McpResourceTemplateDefinition(
			uriTemplate: 'tcms://products/{id}',
			name: 'Products',
			description: 'Product catalog',
			mimeType: 'application/json',
			access: 'public',
			handler: $handler,
		);

		$this->assertSame('tcms://products/{id}', $tmpl->uriTemplate);
		$this->assertSame('Products', $tmpl->name);
		$this->assertSame('Product catalog', $tmpl->description);
		$this->assertSame('application/json', $tmpl->mimeType);
		$this->assertSame('public', $tmpl->access);
		$this->assertSame($handler, $tmpl->handler);
	}

	public function testAdminPersonaSeesAdminTemplate(): void
	{
		$this->assertTrue($this->template('admin')->isVisibleTo(McpPersona::ADMIN));
	}

	public function testAdminPersonaSeesPublicTemplate(): void
	{
		$this->assertTrue($this->template('public')->isVisibleTo(McpPersona::ADMIN));
	}

	public function testAdminPersonaSeesAuthenticatedTemplate(): void
	{
		$this->assertTrue($this->template('authenticated')->isVisibleTo(McpPersona::ADMIN));
	}

	public function testPublicPersonaCannotSeeAdminTemplate(): void
	{
		$this->assertFalse($this->template('admin')->isVisibleTo(McpPersona::PUBLIC_));
	}

	public function testPublicPersonaSeesPublicTemplate(): void
	{
		$this->assertTrue($this->template('public')->isVisibleTo(McpPersona::PUBLIC_));
	}

	public function testPublicPersonaCannotSeeAuthenticatedTemplate(): void
	{
		$this->assertFalse($this->template('authenticated')->isVisibleTo(McpPersona::PUBLIC_));
	}

	public function testAuthenticatedPersonaCannotSeeAdminTemplate(): void
	{
		$this->assertFalse($this->template('admin')->isVisibleTo(McpPersona::AUTHENTICATED));
	}

	public function testAuthenticatedPersonaSeesPublicTemplate(): void
	{
		$this->assertTrue($this->template('public')->isVisibleTo(McpPersona::AUTHENTICATED));
	}

	public function testAuthenticatedPersonaSeesAuthenticatedTemplate(): void
	{
		$this->assertTrue($this->template('authenticated')->isVisibleTo(McpPersona::AUTHENTICATED));
	}
}
