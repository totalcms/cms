<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Prompt\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Prompt\Data\PromptArgData;
use TotalCMS\Domain\Mcp\Prompt\Data\PromptData;

final class PromptDataTest extends TestCase
{
	public function testConstructAssignsAllFields(): void
	{
		$arg = new PromptArgData('topic');
		$prompt = new PromptData(
			name:             'draft_post',
			description:      'Outline a new blog post',
			body:             'Draft a post about: {{ args.topic }}',
			args:             [$arg],
			targetCollection: 'blog',
			access:           'authenticated',
		);
		$this->assertSame('draft_post', $prompt->name);
		$this->assertSame('Outline a new blog post', $prompt->description);
		$this->assertSame('Draft a post about: {{ args.topic }}', $prompt->body);
		$this->assertCount(1, $prompt->args);
		$this->assertSame('blog', $prompt->targetCollection);
		$this->assertSame('authenticated', $prompt->access);
	}

	public function testFromArrayAcceptsMinimal(): void
	{
		$prompt = PromptData::fromArray([
			'name'        => 'minimal',
			'description' => 'desc',
			'body'        => 'hello',
		]);
		$this->assertSame('minimal', $prompt->name);
		$this->assertSame([], $prompt->args);
		$this->assertSame('', $prompt->targetCollection);
		$this->assertSame('', $prompt->access);
	}

	public function testFromArrayParsesArgsAsDeck(): void
	{
		// Deck shape: keyed by arg name. This is the storage form.
		$prompt = PromptData::fromArray([
			'name'        => 'with_args',
			'description' => 'desc',
			'body'        => 'hello',
			'args'        => [
				'topic' => ['id' => 'topic', 'description' => 'The topic', 'required' => true],
				'tone'  => ['id' => 'tone'],
			],
		]);
		$this->assertCount(2, $prompt->args);
		$this->assertSame('topic', $prompt->args[0]->name);
		$this->assertSame('The topic', $prompt->args[0]->description);
		$this->assertTrue($prompt->args[0]->required);
		$this->assertSame('tone', $prompt->args[1]->name);
		$this->assertFalse($prompt->args[1]->required);
	}

	public function testFromArrayTakesDeckKeyAsCanonicalName(): void
	{
		// If `id` and the key disagree, the deck key wins (it's how the form
		// builder stores the canonical name).
		$prompt = PromptData::fromArray([
			'name'        => 'p',
			'description' => 'd',
			'body'        => 'b',
			'args'        => [
				'real_name' => ['id' => 'stale_id'],
			],
		]);
		$this->assertSame('real_name', $prompt->args[0]->name);
	}

	public function testFromArrayDropsArgsWithoutName(): void
	{
		// Robustness: list-shaped or malformed entries that yield no name are skipped.
		$prompt = PromptData::fromArray([
			'name'        => 'p',
			'description' => 'd',
			'body'        => 'b',
			'args'        => [
				0       => ['description' => 'no id, no key'],   // list index, no usable name
				'topic' => ['id' => 'topic'],
			],
		]);
		$this->assertCount(1, $prompt->args);
		$this->assertSame('topic', $prompt->args[0]->name);
	}
}
