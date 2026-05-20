<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Service\PersonaContext;

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
}
