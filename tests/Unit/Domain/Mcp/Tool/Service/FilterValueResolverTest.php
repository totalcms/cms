<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tool\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Tool\Exception\SavedQueryToolException;
use TotalCMS\Domain\Mcp\Tool\Service\FilterValueResolver;

final class FilterValueResolverTest extends TestCase
{
	private FilterValueResolver $resolver;

	protected function setUp(): void
	{
		$this->resolver = new FilterValueResolver();
	}

	public function testReturnsLiteralsUntouched(): void
	{
		$out = $this->resolver->resolve('active', [], [], 'tool_x');
		$this->assertSame('active', $out);
	}

	public function testReturnsNonStringLiteralsUntouched(): void
	{
		$this->assertTrue($this->resolver->resolve(true, [], [], 'tool_x'));
		$this->assertSame(42, $this->resolver->resolve(42, [], [], 'tool_x'));
	}

	public function testSubstitutesASingleParamsPlaceholder(): void
	{
		$out = $this->resolver->resolve(
			'{{params.city}}',
			['city' => 'Austin'],
			['city' => ['type' => 'string']],
			'tool_x',
		);
		$this->assertSame('Austin', $out);
	}

	public function testCoercesSubstitutedValueToDeclaredType(): void
	{
		$out = $this->resolver->resolve(
			'{{params.max_price}}',
			['max_price' => '500000'],
			['max_price' => ['type' => 'number']],
			'tool_x',
		);
		$this->assertSame(500000.0, $out);
	}

	public function testReturnsNullWhenOptionalParamMissingAndValueIsPurePlaceholder(): void
	{
		$out = $this->resolver->resolve(
			'{{params.max_price}}',
			[],
			['max_price' => ['type' => 'number']],
			'tool_x',
		);
		$this->assertNull($out);
	}

	public function testThrowsWhenPlaceholderReferencesUndeclaredParam(): void
	{
		$this->expectException(SavedQueryToolException::class);
		$this->expectExceptionMessageMatches('/unknown/');

		$this->resolver->resolve(
			'{{params.unknown}}',
			['city' => 'Austin'],
			['city' => ['type' => 'string']],
			'find_listings',
		);
	}

	public function testTreatsNonParamsSubstringsAsLiterals(): void
	{
		$out = $this->resolver->resolve(
			'prefix-{{this_month_start}}-suffix',
			['city' => 'Austin'],
			['city' => ['type' => 'string']],
			'tool_x',
		);
		$this->assertSame('prefix-{{this_month_start}}-suffix', $out);
	}

	public function testSubstitutesMultipleParamsInASingleString(): void
	{
		$out = $this->resolver->resolve(
			'{{params.first}}_{{params.second}}',
			['first' => 'a', 'second' => 'b'],
			['first' => ['type' => 'string'], 'second' => ['type' => 'string']],
			'tool_x',
		);
		$this->assertSame('a_b', $out);
	}
}
