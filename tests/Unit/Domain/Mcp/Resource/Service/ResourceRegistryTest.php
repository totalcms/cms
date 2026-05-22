<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Resource\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Resource\Data\McpResourceDefinition;
use TotalCMS\Domain\Mcp\Resource\Data\McpResourceTemplateDefinition;
use TotalCMS\Domain\Mcp\Resource\Service\ResourceRegistry;

final class ResourceRegistryTest extends TestCase
{
	private ResourceRegistry $registry;

	protected function setUp(): void
	{
		$this->registry = new ResourceRegistry();
	}

	// ── Helper factories ──────────────────────────────────────────────────────

	private function resource(string $uri, string $access = 'public'): McpResourceDefinition
	{
		return new McpResourceDefinition(
			uri: $uri,
			name: 'Name-' . $uri,
			description: 'desc',
			mimeType: 'application/json',
			access: $access,
			handler: static fn (): array => [],
		);
	}

	private function template(string $uriTemplate, string $access = 'public'): McpResourceTemplateDefinition
	{
		return new McpResourceTemplateDefinition(
			uriTemplate: $uriTemplate,
			name: 'Name-' . $uriTemplate,
			description: 'desc',
			mimeType: 'application/json',
			access: $access,
			handler: static fn (): array => [],
		);
	}

	// ── Concrete resource tests ───────────────────────────────────────────────

	public function testRegisterThenGetReturnsSameInstance(): void
	{
		$res = $this->resource('tcms://blog/');

		$this->registry->register($res);

		$this->assertSame($res, $this->registry->get('tcms://blog/'));
	}

	public function testGetReturnsNullForUnknownUri(): void
	{
		$this->assertNull($this->registry->get('tcms://missing/'));
	}

	public function testRegisterThrowsOnDuplicateUri(): void
	{
		$this->registry->register($this->resource('tcms://blog/'));

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('MCP resource "tcms://blog/" is already registered.');

		$this->registry->register($this->resource('tcms://blog/'));
	}

	public function testUnregisterRemovesResource(): void
	{
		$this->registry->register($this->resource('tcms://blog/'));

		$this->registry->unregister('tcms://blog/');

		$this->assertNull($this->registry->get('tcms://blog/'));
	}

	public function testUnregisterIsIdempotent(): void
	{
		// Should not throw when URI was never registered.
		$this->registry->unregister('tcms://never/');

		$this->assertNull($this->registry->get('tcms://never/'));
	}

	public function testAllReturnsEveryRegisteredResourceRegardlessOfPersona(): void
	{
		$this->registry->register($this->resource('tcms://a/', 'public'));
		$this->registry->register($this->resource('tcms://b/', 'admin'));
		$this->registry->register($this->resource('tcms://c/', 'authenticated'));

		$this->assertCount(3, $this->registry->all());
	}

	public function testForPersonaPublicFiltersOutAdminResources(): void
	{
		$this->registry->register($this->resource('tcms://public/', 'public'));
		$this->registry->register($this->resource('tcms://admin/', 'admin'));

		$visible = $this->registry->forPersona(McpPersona::PUBLIC_);

		$this->assertCount(1, $visible);
		$this->assertSame('tcms://public/', $visible[0]->uri);
	}

	public function testForPersonaAdminSeesAllResources(): void
	{
		$this->registry->register($this->resource('tcms://public/', 'public'));
		$this->registry->register($this->resource('tcms://admin/', 'admin'));
		$this->registry->register($this->resource('tcms://auth/', 'authenticated'));

		$visible = $this->registry->forPersona(McpPersona::ADMIN);

		$this->assertCount(3, $visible);
	}

	public function testForPersonaOutputIsSortedAlphabeticallyByUri(): void
	{
		// Register in reverse order on purpose.
		$this->registry->register($this->resource('tcms://zebra/', 'public'));
		$this->registry->register($this->resource('tcms://alpha/', 'public'));
		$this->registry->register($this->resource('tcms://mango/', 'public'));

		$visible = $this->registry->forPersona(McpPersona::PUBLIC_);

		$this->assertCount(3, $visible);
		$this->assertSame(['tcms://alpha/', 'tcms://mango/', 'tcms://zebra/'], array_map(
			static fn (McpResourceDefinition $r): string => $r->uri,
			$visible,
		));
	}

	public function testForPersonaReturnsEmptyArrayWhenNoMatches(): void
	{
		$this->registry->register($this->resource('tcms://admin-only/', 'admin'));

		$this->assertSame([], $this->registry->forPersona(McpPersona::PUBLIC_));
	}

	// ── Resource template tests ───────────────────────────────────────────────

	public function testRegisterTemplateThenGetTemplateReturnsSameInstance(): void
	{
		$tmpl = $this->template('tcms://blog/{id}');

		$this->registry->registerTemplate($tmpl);

		$this->assertSame($tmpl, $this->registry->getTemplate('tcms://blog/{id}'));
	}

	public function testGetTemplateReturnsNullForUnknownUriTemplate(): void
	{
		$this->assertNull($this->registry->getTemplate('tcms://missing/{id}'));
	}

	public function testRegisterTemplateThrowsOnDuplicateUriTemplate(): void
	{
		$this->registry->registerTemplate($this->template('tcms://blog/{id}'));

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('MCP resource template "tcms://blog/{id}" is already registered.');

		$this->registry->registerTemplate($this->template('tcms://blog/{id}'));
	}

	public function testUnregisterTemplateRemovesEntry(): void
	{
		$this->registry->registerTemplate($this->template('tcms://blog/{id}'));

		$this->registry->unregisterTemplate('tcms://blog/{id}');

		$this->assertNull($this->registry->getTemplate('tcms://blog/{id}'));
	}

	public function testUnregisterTemplateIsIdempotent(): void
	{
		// Should not throw when template was never registered.
		$this->registry->unregisterTemplate('tcms://never/{id}');

		$this->assertNull($this->registry->getTemplate('tcms://never/{id}'));
	}

	public function testAllTemplatesReturnsEveryRegisteredTemplateRegardlessOfPersona(): void
	{
		$this->registry->registerTemplate($this->template('tcms://a/{id}', 'public'));
		$this->registry->registerTemplate($this->template('tcms://b/{id}', 'admin'));
		$this->registry->registerTemplate($this->template('tcms://c/{id}', 'authenticated'));

		$this->assertCount(3, $this->registry->allTemplates());
	}

	public function testTemplatesForPersonaPublicFiltersOutAdminTemplates(): void
	{
		$this->registry->registerTemplate($this->template('tcms://public/{id}', 'public'));
		$this->registry->registerTemplate($this->template('tcms://admin/{id}', 'admin'));

		$visible = $this->registry->templatesForPersona(McpPersona::PUBLIC_);

		$this->assertCount(1, $visible);
		$this->assertSame('tcms://public/{id}', $visible[0]->uriTemplate);
	}

	public function testTemplatesForPersonaAdminSeesAllTemplates(): void
	{
		$this->registry->registerTemplate($this->template('tcms://public/{id}', 'public'));
		$this->registry->registerTemplate($this->template('tcms://admin/{id}', 'admin'));
		$this->registry->registerTemplate($this->template('tcms://auth/{id}', 'authenticated'));

		$visible = $this->registry->templatesForPersona(McpPersona::ADMIN);

		$this->assertCount(3, $visible);
	}

	public function testTemplatesForPersonaOutputIsSortedAlphabeticallyByUriTemplate(): void
	{
		// Register in reverse order on purpose.
		$this->registry->registerTemplate($this->template('tcms://zebra/{id}', 'public'));
		$this->registry->registerTemplate($this->template('tcms://alpha/{id}', 'public'));
		$this->registry->registerTemplate($this->template('tcms://mango/{id}', 'public'));

		$visible = $this->registry->templatesForPersona(McpPersona::PUBLIC_);

		$this->assertCount(3, $visible);
		$this->assertSame(['tcms://alpha/{id}', 'tcms://mango/{id}', 'tcms://zebra/{id}'], array_map(
			static fn (McpResourceTemplateDefinition $t): string => $t->uriTemplate,
			$visible,
		));
	}

	public function testTemplatesForPersonaReturnsEmptyArrayWhenNoMatches(): void
	{
		$this->registry->registerTemplate($this->template('tcms://admin-only/{id}', 'admin'));

		$this->assertSame([], $this->registry->templatesForPersona(McpPersona::PUBLIC_));
	}
}
