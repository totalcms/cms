<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Prompt\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Prompt\Data\PromptArgData;

final class PromptArgDataTest extends TestCase
{
	public function testConstructAssignsAllFields(): void
	{
		$arg = new PromptArgData('topic', 'The blog topic', true);
		$this->assertSame('topic', $arg->name);
		$this->assertSame('The blog topic', $arg->description);
		$this->assertTrue($arg->required);
	}

	public function testFromArrayWithIdKey(): void
	{
		// Deck-item shape — the storage form.
		$arg = PromptArgData::fromArray(['id' => 'topic']);
		$this->assertSame('topic', $arg->name);
		$this->assertSame('', $arg->description);
		$this->assertFalse($arg->required);
	}

	public function testFromArrayWithNameKey(): void
	{
		// Legacy / programmatic construction.
		$arg = PromptArgData::fromArray(['name' => 'topic']);
		$this->assertSame('topic', $arg->name);
	}

	public function testFromArrayAcceptsAllFields(): void
	{
		$arg = PromptArgData::fromArray([
			'id'          => 'topic',
			'description' => 'The blog topic',
			'required'    => true,
		]);
		$this->assertSame('topic', $arg->name);
		$this->assertSame('The blog topic', $arg->description);
		$this->assertTrue($arg->required);
	}
}
