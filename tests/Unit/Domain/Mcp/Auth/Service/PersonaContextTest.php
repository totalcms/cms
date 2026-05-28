<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Auth\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;

final class PersonaContextTest extends TestCase
{
	public function testFreshContextIsUnresolved(): void
	{
		$context = new PersonaContext();

		$this->assertFalse($context->isResolved());
	}

	public function testCurrentThrowsBeforeSet(): void
	{
		$context = new PersonaContext();

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('Persona has not been resolved');

		$context->current();
	}

	public function testSetMakesContextResolved(): void
	{
		$context = new PersonaContext();
		$context->set(McpPersona::ADMIN);

		$this->assertTrue($context->isResolved());
		$this->assertSame(McpPersona::ADMIN, $context->current());
	}

	public function testSetOverwritesPreviousValue(): void
	{
		// Mid-request persona changes shouldn't happen in production, but the
		// behavior should be predictable: last-write-wins, no exception.
		$context = new PersonaContext();
		$context->set(McpPersona::ADMIN);
		$context->set(McpPersona::PUBLIC_);

		$this->assertSame(McpPersona::PUBLIC_, $context->current());
	}

	// ── Scopes ──────────────────────────────────────────────────────────────────

	public function testGetScopesDefaultsToEmptyArray(): void
	{
		$context = new PersonaContext();

		$this->assertSame([], $context->getScopes());
	}

	public function testSetScopesStoresScopes(): void
	{
		$context = new PersonaContext();
		$context->setScopes(['mcp:tools', 'mcp:resources']);

		$this->assertSame(['mcp:tools', 'mcp:resources'], $context->getScopes());
	}

	public function testSetScopesOverwritesPreviousScopes(): void
	{
		// Last-write-wins — consistent with persona behaviour.
		$context = new PersonaContext();
		$context->setScopes(['mcp:tools']);
		$context->setScopes(['mcp:resources', 'cms:read']);

		$this->assertSame(['mcp:resources', 'cms:read'], $context->getScopes());
	}

	public function testSetScopesWithEmptyArrayClearsScopes(): void
	{
		$context = new PersonaContext();
		$context->setScopes(['mcp:tools']);
		$context->setScopes([]);

		$this->assertSame([], $context->getScopes());
	}

	public function testScopesAreIndependentOfPersona(): void
	{
		// Scopes and persona are orthogonal — setting one doesn't affect the other.
		$context = new PersonaContext();
		$context->set(McpPersona::AUTHENTICATED);
		$context->setScopes(['mcp:tools']);

		$this->assertSame(McpPersona::AUTHENTICATED, $context->current());
		$this->assertSame(['mcp:tools'], $context->getScopes());
	}
}
