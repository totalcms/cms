<?php

namespace Tests\Unit\Domain\Property\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Property\Data\CodeData;

final class CodeDataTest extends TestCase
{
	public function testConstructorSetsCode(): void
	{
		$code = new CodeData('console.log("hello");');

		$this->assertSame('console.log("hello");', (string)$code);
	}

	public function testConstructorWithEmptyCode(): void
	{
		$code = new CodeData();

		$this->assertSame('', (string)$code);
	}

	public function testTransformReturnsString(): void
	{
		$code = new CodeData('function test() {}');

		$this->assertSame('function test() {}', $code->transform());
	}

	public function testToStringReturnsCode(): void
	{
		$code = new CodeData('const x = 1;');

		$this->assertSame('const x = 1;', (string)$code);
	}

	public function testContainsHTMLReturnsTrueForHTML(): void
	{
		$code = new CodeData('<div>Hello</div>');

		$this->assertTrue($code->containsHTML());
	}

	public function testContainsHTMLReturnsFalseForPlainText(): void
	{
		$code = new CodeData('console.log("test");');

		$this->assertFalse($code->containsHTML());
	}

	public function testContainsHTMLReturnsFalseForEmpty(): void
	{
		$code = new CodeData('');

		$this->assertFalse($code->containsHTML());
	}

	public function testCodeWithSettings(): void
	{
		$code = new CodeData('const x = 1;', ['language' => 'javascript']);

		$this->assertSame('const x = 1;', $code->transform());
	}

	public function testCodePreservesMultilineContent(): void
	{
		$multilineCode = "function test() {\n  return true;\n}";
		$code          = new CodeData($multilineCode);

		$this->assertSame($multilineCode, (string)$code);
	}

	public function testCodeWithSpecialCharacters(): void
	{
		$codeWithSpecials = 'if (a < b && c > d) { return "test"; }';
		$code             = new CodeData($codeWithSpecials);

		$this->assertSame($codeWithSpecials, (string)$code);
	}
}
