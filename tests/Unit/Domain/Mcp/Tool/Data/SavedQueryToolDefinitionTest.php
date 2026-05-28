<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tool\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Tool\Data\SavedQueryToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Exception\SavedQueryToolException;

final class SavedQueryToolDefinitionTest extends TestCase
{
	public function testConstructsFromValidDefinitionArray(): void
	{
		$def = SavedQueryToolDefinition::fromArray('listings', 'public', [
			'id'          => 'find_listings',
			'description' => 'Find listings.',
			'params'      => [
				'city' => ['type' => 'string', 'required' => true],
			],
			'filters' => [
				'status' => ['value' => 'active'],
				'city'   => ['operator' => 'contains', 'value' => '{{params.city}}'],
			],
			'sort'   => 'price:asc',
			'limit'  => 20,
			'format' => 'markdown',
		]);

		$this->assertSame('find_listings', $def->name);
		$this->assertSame('listings', $def->collectionName);
		$this->assertSame('public', $def->access);
		$this->assertArrayHasKey('city', $def->params);
		$this->assertSame('contains', $def->filters['city']['operator']);
		$this->assertSame('price:asc', $def->sort);
		$this->assertSame(20, $def->limit);
		$this->assertSame('markdown', $def->format);
	}

	public function testThrowsValidationExceptionOnMissingId(): void
	{
		$this->expectException(SavedQueryToolException::class);
		$this->expectExceptionMessageMatches('/missing required field: id/');

		SavedQueryToolDefinition::fromArray('blog', 'public', [
			'description' => 'No id field.',
		]);
	}

	public function testAcceptsLegacyNameKeyForBackwardCompat(): void
	{
		// fromArray() reads `id` first but falls back to `name` so existing
		// saved-query JSON written before the schema rename still loads.
		$def = SavedQueryToolDefinition::fromArray('blog', 'public', [
			'name'        => 'legacy_tool',
			'description' => 'Saved before the id rename.',
		]);

		$this->assertSame('legacy_tool', $def->name);
	}

	public function testThrowsValidationOnSnakeCaseViolation(): void
	{
		$this->expectException(SavedQueryToolException::class);
		$this->expectExceptionMessageMatches('/must match/');

		SavedQueryToolDefinition::fromArray('blog', 'public', [
			'id'          => 'BadName',
			'description' => '...',
		]);
	}

	public function testClampsLimitTo50Max(): void
	{
		$def = SavedQueryToolDefinition::fromArray('blog', 'public', [
			'id'          => 'big_query',
			'description' => '...',
			'limit'       => 1000,
		]);

		$this->assertSame(50, $def->limit);
	}

	public function testDefaultsLimitOffsetFormatWhenOmitted(): void
	{
		$def = SavedQueryToolDefinition::fromArray('blog', 'public', [
			'id'          => 'minimal',
			'description' => '...',
		]);

		$this->assertSame(20, $def->limit);
		$this->assertSame(0, $def->offset);
		$this->assertSame('markdown', $def->format);
		$this->assertSame([], $def->params);
		$this->assertSame([], $def->filters);
	}

	public function testRejectsUnknownOperator(): void
	{
		$this->expectException(SavedQueryToolException::class);
		$this->expectExceptionMessageMatches('/unsupported operator: NOTSUPPORTED/');

		SavedQueryToolDefinition::fromArray('blog', 'public', [
			'id'          => 'bad_op',
			'description' => '...',
			'filters'     => [
				'field' => ['operator' => 'NOTSUPPORTED', 'value' => 'x'],
			],
		]);
	}

	public function testInvalidParamNameRejected(): void
	{
		$this->expectException(SavedQueryToolException::class);
		$this->expectExceptionMessageMatches('/param name must match snake_case: CamelCase/');

		SavedQueryToolDefinition::fromArray('blog', 'public', [
			'id'          => 'find_things',
			'description' => 'A tool.',
			'params'      => [
				'CamelCase' => ['type' => 'string'],
			],
		]);
	}
}
