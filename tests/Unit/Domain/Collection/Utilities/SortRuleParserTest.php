<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Collection\Utilities;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Utilities\SortRuleParser;

final class SortRuleParserTest extends TestCase
{
	public function testParsesEmptyString(): void
	{
		$rules = SortRuleParser::parse('');

		$this->assertSame([], $rules);
	}

	public function testParsesSingleDescending(): void
	{
		$rules = SortRuleParser::parse('date:desc');

		$this->assertCount(1, $rules);
		$this->assertSame('date', $rules[0]['property']);
		$this->assertTrue($rules[0]['reverse']);
		$this->assertFalse($rules[0]['natural']);
	}

	public function testParsesSingleAscending(): void
	{
		$rules = SortRuleParser::parse('title:asc');

		$this->assertCount(1, $rules);
		$this->assertSame('title', $rules[0]['property']);
		$this->assertFalse($rules[0]['reverse']);
		$this->assertFalse($rules[0]['natural']);
	}

	public function testDefaultsToAscending(): void
	{
		$rules = SortRuleParser::parse('title');

		$this->assertCount(1, $rules);
		$this->assertSame('title', $rules[0]['property']);
		$this->assertFalse($rules[0]['reverse']);
	}

	public function testParsesNaturalSort(): void
	{
		$rules = SortRuleParser::parse('title:asc:natural');

		$this->assertCount(1, $rules);
		$this->assertSame('title', $rules[0]['property']);
		$this->assertFalse($rules[0]['reverse']);
		$this->assertTrue($rules[0]['natural']);
	}

	public function testParsesMultipleCriteria(): void
	{
		$rules = SortRuleParser::parse('date:desc,title:asc:natural');

		$this->assertCount(2, $rules);
		$this->assertSame('date', $rules[0]['property']);
		$this->assertTrue($rules[0]['reverse']);
		$this->assertSame('title', $rules[1]['property']);
		$this->assertFalse($rules[1]['reverse']);
		$this->assertTrue($rules[1]['natural']);
	}

	public function testParsesShuffle(): void
	{
		$rules = SortRuleParser::parse('shuffle');

		$this->assertCount(1, $rules);
		$this->assertTrue($rules[0]['shuffle']);
	}

	public function testParsesShuffleWithProperty(): void
	{
		$rules = SortRuleParser::parse('date:desc,shuffle');

		$this->assertCount(2, $rules);
		$this->assertSame('date', $rules[0]['property']);
		$this->assertTrue($rules[1]['shuffle']);
	}

	public function testIgnoresEmptySegments(): void
	{
		$rules = SortRuleParser::parse('date:desc,,title:asc');

		$this->assertCount(2, $rules);
	}

	public function testHandlesWhitespace(): void
	{
		$rules = SortRuleParser::parse(' date : desc , title : asc ');

		$this->assertCount(2, $rules);
		$this->assertSame('date', $rules[0]['property']);
		$this->assertTrue($rules[0]['reverse']);
		$this->assertSame('title', $rules[1]['property']);
		$this->assertFalse($rules[1]['reverse']);
	}

	public function testShuffleIsCaseInsensitive(): void
	{
		$rules = SortRuleParser::parse('SHUFFLE');

		$this->assertCount(1, $rules);
		$this->assertTrue($rules[0]['shuffle']);
	}

	public function testDirectionIsCaseInsensitive(): void
	{
		$rules = SortRuleParser::parse('date:DESC');

		$this->assertCount(1, $rules);
		$this->assertTrue($rules[0]['reverse']);
	}
}
