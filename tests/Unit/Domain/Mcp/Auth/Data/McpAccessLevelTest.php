<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Auth\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Auth\Data\McpAccessLevel;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;

// McpAccessLevel is the single persona-versus-access rule for MCP tools,
// resources, prompts and data views. Every exposure decision in the server
// routes through allows(), so the full matrix is pinned here rather than
// re-tested per consumer.
final class McpAccessLevelTest extends TestCase
{
	/** @return iterable<string, array{McpAccessLevel, McpPersona, bool}> */
	public static function matrix(): iterable
	{
		yield 'public lets anonymous in'          => [McpAccessLevel::PUBLIC_, McpPersona::PUBLIC_, true];
		yield 'public lets authenticated in'      => [McpAccessLevel::PUBLIC_, McpPersona::AUTHENTICATED, true];
		yield 'public lets admin in'              => [McpAccessLevel::PUBLIC_, McpPersona::ADMIN, true];
		yield 'authenticated denies anonymous'    => [McpAccessLevel::AUTHENTICATED, McpPersona::PUBLIC_, false];
		yield 'authenticated lets authenticated'  => [McpAccessLevel::AUTHENTICATED, McpPersona::AUTHENTICATED, true];
		yield 'authenticated lets admin'          => [McpAccessLevel::AUTHENTICATED, McpPersona::ADMIN, true];
		yield 'admin denies anonymous'            => [McpAccessLevel::ADMIN, McpPersona::PUBLIC_, false];
		yield 'admin denies authenticated'        => [McpAccessLevel::ADMIN, McpPersona::AUTHENTICATED, false];
		yield 'admin lets admin'                  => [McpAccessLevel::ADMIN, McpPersona::ADMIN, true];
	}

	/** @dataProvider matrix */
	public function testAllowsFollowsTheMatrix(McpAccessLevel $level, McpPersona $persona, bool $expected): void
	{
		$this->assertSame($expected, $level->allows($persona));
	}

	public function testKnownValuesResolveToTheirLevel(): void
	{
		$this->assertSame(McpAccessLevel::PUBLIC_, McpAccessLevel::fromString('public'));
		$this->assertSame(McpAccessLevel::AUTHENTICATED, McpAccessLevel::fromString('authenticated'));
		$this->assertSame(McpAccessLevel::ADMIN, McpAccessLevel::fromString('admin'));
	}

	public function testUnknownValuesFailClosedToAdminNotToNobody(): void
	{
		// A malformed setting must never widen exposure, and must never lock
		// the admin out either — that second half is what one of the former
		// copies got wrong.
		foreach (['', 'Public', 'everyone', 'bogus', null] as $raw) {
			$level = McpAccessLevel::fromString($raw);

			$this->assertSame(McpAccessLevel::ADMIN, $level, var_export($raw, true));
			$this->assertFalse($level->allows(McpPersona::PUBLIC_));
			$this->assertFalse($level->allows(McpPersona::AUTHENTICATED));
			$this->assertTrue($level->allows(McpPersona::ADMIN));
		}
	}
}
