<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Service\ToolsOnlyClients;

final class ToolsOnlyClientsTest extends TestCase
{
	public function testOpenAiClientMatches(): void
	{
		// The real ChatGPT connector identifier (from mcp-activity.log).
		$this->assertTrue(ToolsOnlyClients::matches('openai-mcp'));
	}

	public function testChatGptVariantsMatchCaseInsensitively(): void
	{
		$this->assertTrue(ToolsOnlyClients::matches('ChatGPT'));
		$this->assertTrue(ToolsOnlyClients::matches('OpenAI-MCP'));
		$this->assertTrue(ToolsOnlyClients::matches('chatgpt-deep-research'));
	}

	public function testFullFeaturedClientsDoNotMatch(): void
	{
		// These keep the rich surface (resources + prompts).
		$this->assertFalse(ToolsOnlyClients::matches('Anthropic/ClaudeAI'));
		$this->assertFalse(ToolsOnlyClients::matches('claude-ai'));
		$this->assertFalse(ToolsOnlyClients::matches('Cursor'));
		$this->assertFalse(ToolsOnlyClients::matches('mcp-inspector'));
	}

	public function testEmptyOrUnknownNameDoesNotMatch(): void
	{
		// Default-safe: no name (non-initialize requests) → full surface.
		$this->assertFalse(ToolsOnlyClients::matches(''));
		$this->assertFalse(ToolsOnlyClients::matches('some-random-client'));
	}
}
