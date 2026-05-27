<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Auth\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;

final class McpPersonaTest extends TestCase
{
	public function testAllCasesExposeExpectedStringValues(): void
	{
		$this->assertSame('admin', McpPersona::ADMIN->value);
		$this->assertSame('public', McpPersona::PUBLIC_->value);
		$this->assertSame('authenticated', McpPersona::AUTHENTICATED->value);
	}

	public function testEnumHasExactlyThreeCases(): void
	{
		$this->assertCount(3, McpPersona::cases());
	}

	public function testFromHandlesEachStringValue(): void
	{
		$this->assertSame(McpPersona::ADMIN, McpPersona::from('admin'));
		$this->assertSame(McpPersona::PUBLIC_, McpPersona::from('public'));
		$this->assertSame(McpPersona::AUTHENTICATED, McpPersona::from('authenticated'));
	}
}
