<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Service\ObjectTitleResolver;

final class ObjectTitleResolverTest extends TestCase
{
	private ObjectTitleResolver $resolver;

	protected function setUp(): void
	{
		$this->resolver = new ObjectTitleResolver();
	}

	public function testExplicitTitlePropertyWins(): void
	{
		$object = ['id' => 'p1', 'title' => 'Convention', 'headline' => 'Explicit Pick'];
		$this->assertSame('Explicit Pick', $this->resolver->resolve($object, 'headline'));
	}

	public function testFallsBackToConventionWhenTitlePropertyEmptyOrMissing(): void
	{
		$object = ['id' => 'p1', 'name' => 'By Name'];
		$this->assertSame('By Name', $this->resolver->resolve($object, ''));
		$this->assertSame('By Name', $this->resolver->resolve($object, 'nonexistent'));
	}

	public function testTitlePropertyNamingNonScalarIsIgnored(): void
	{
		$object = ['id' => 'p1', 'title' => 'Good', 'gallery' => ['a', 'b']];
		$this->assertSame('Good', $this->resolver->resolve($object, 'gallery'));
	}

	public function testFallsBackToIdWhenNothingElsePresent(): void
	{
		$object = ['id' => 'only-the-id', 'body' => 'no title-ish field'];
		$this->assertSame('only-the-id', $this->resolver->resolve($object, ''));
	}

	public function testStripsHtmlAndCollapsesWhitespace(): void
	{
		$object = ['id' => 'p1', 'title' => '  <b>Hello</b>   World  '];
		$this->assertSame('Hello World', $this->resolver->resolve($object, ''));
	}

	public function testTruncatesToMaxLength(): void
	{
		$long = str_repeat('a', 300);
		$this->assertSame(200, mb_strlen($this->resolver->resolve(['id' => 'p1', 'title' => $long], '')));
	}

	public function testExplicitConventionTitleWinsOverLaterKeys(): void
	{
		$object = ['id' => 'p1', 'title' => 'The Title', 'name' => 'The Name'];
		$this->assertSame('The Title', $this->resolver->resolve($object, ''));
	}

	public function testFallsBackToScalarIdThatIsNotString(): void
	{
		$object = ['id' => 42, 'body' => 'x'];
		$this->assertSame('42', $this->resolver->resolve($object, ''));
	}

	public function testEmptyObjectReturnsEmptyString(): void
	{
		$this->assertSame('', $this->resolver->resolve([], ''));
	}

	public function testDecodesHtmlEntities(): void
	{
		$object = ['id' => 'p1', 'title' => 'Tom &amp; Jerry'];
		$this->assertSame('Tom & Jerry', $this->resolver->resolve($object, ''));
	}
}
