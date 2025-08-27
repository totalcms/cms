<?php

declare(strict_types=1);

namespace Tests\Unit\Twig\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Twig\Service\GridRenderer;

final class GridRendererTest extends TestCase
{
	private GridRenderer $gridRenderer;

	protected function setUp(): void
	{
		$this->gridRenderer = new GridRenderer();
	}

	public function testMetaWithValidData(): void
	{
		$result = $this->gridRenderer->meta('Author Name');

		$this->assertIsString($result);
		$this->assertStringContainsString('Author Name', $result);
		$this->assertStringContainsString('class="cms-meta"', $result);
	}

	public function testMetaWithEmptyString(): void
	{
		$result = $this->gridRenderer->meta('');

		$this->assertEquals('', $result);
	}

	public function testTagsWithArrayInput(): void
	{
		$tags   = ['php', 'web', 'development'];
		$result = $this->gridRenderer->tags($tags);

		$this->assertIsString($result);
		$this->assertStringContainsString('php', $result);
		$this->assertStringContainsString('web', $result);
		$this->assertStringContainsString('development', $result);
	}

	public function testTagsWithStringInput(): void
	{
		$tags   = 'php, web, development';
		$result = $this->gridRenderer->tags($tags);

		$this->assertIsString($result);
		$this->assertStringContainsString('php', $result);
		$this->assertStringContainsString('web', $result);
		$this->assertStringContainsString('development', $result);
	}

	public function testTagsWithLinkBase(): void
	{
		$tags     = ['php', 'web'];
		$linkBase = '/tags/';
		$result   = $this->gridRenderer->tags($tags, $linkBase);

		$this->assertIsString($result);
		$this->assertStringContainsString('/tags/', $result);
		$this->assertStringContainsString('php', $result);
		$this->assertStringContainsString('web', $result);
	}

	public function testTagsWithNullLinkBase(): void
	{
		$tags   = ['php', 'web'];
		$result = $this->gridRenderer->tags($tags, null);

		$this->assertIsString($result);
		$this->assertStringContainsString('php', $result);
		$this->assertStringContainsString('web', $result);
		// Should not contain links when linkBase is null
		$this->assertStringNotContainsString('<a', $result);
	}

	public function testTagsWithEmptyArray(): void
	{
		$result = $this->gridRenderer->tags([]);

		$this->assertEquals('', $result);
	}

	public function testTagsWithNull(): void
	{
		$result = $this->gridRenderer->tags(null);

		$this->assertEquals('', $result);
	}

	public function testTagsWithEmptyString(): void
	{
		$result = $this->gridRenderer->tags('');

		$this->assertEquals('', $result);
	}

	public function testTagsWithStringContainingSpaces(): void
	{
		$tags   = 'php,  web development  , cms ';
		$result = $this->gridRenderer->tags($tags);

		$this->assertIsString($result);
		$this->assertStringContainsString('php', $result);
		$this->assertStringContainsString('web development', $result);
		$this->assertStringContainsString('cms', $result);
	}

	public function testDateWithValidDate(): void
	{
		$date   = '2024-01-15T10:30:00+00:00';
		$result = $this->gridRenderer->date($date);

		$this->assertIsString($result);
		$this->assertStringContainsString('class="cms-date"', $result);
		// Should contain some date representation
		$this->assertNotEmpty($result);
	}

	public function testDateWithCustomFormat(): void
	{
		$date   = '2024-01-15T10:30:00+00:00';
		$result = $this->gridRenderer->date($date, 'short');

		$this->assertIsString($result);
		$this->assertStringContainsString('class="cms-date"', $result);
		$this->assertNotEmpty($result);
	}

	public function testDateWithEmptyString(): void
	{
		$result = $this->gridRenderer->date('');

		$this->assertEquals('', $result);
	}

	public function testDateWithDifferentFormats(): void
	{
		$date = '2024-01-15T10:30:00+00:00';

		$relative = $this->gridRenderer->date($date, 'relative');
		$short    = $this->gridRenderer->date($date, 'short');
		$long     = $this->gridRenderer->date($date, 'long');

		$this->assertIsString($relative);
		$this->assertIsString($short);
		$this->assertIsString($long);
		$this->assertNotEmpty($relative);
		$this->assertNotEmpty($short);
		$this->assertNotEmpty($long);
	}

	public function testExcerptWithValidText(): void
	{
		$text   = 'This is a long piece of text that should be truncated to the specified length for display in grid views.';
		$result = $this->gridRenderer->excerpt($text, 50);

		$this->assertIsString($result);
		$this->assertStringContainsString('<p', $result);
		$this->assertStringContainsString('class="cms-excerpt"', $result);
		$this->assertStringContainsString('This is a long', $result);
	}

	public function testExcerptWithDefaultLength(): void
	{
		$text   = str_repeat('This is a test. ', 20); // Create long text
		$result = $this->gridRenderer->excerpt($text);

		$this->assertIsString($result);
		$this->assertStringContainsString('<p', $result);
		$this->assertStringContainsString('class="cms-excerpt"', $result);
		// Should be truncated (default 100 chars)
		$this->assertLessThan(strlen($text), strlen($result));
	}

	public function testExcerptWithShortText(): void
	{
		$text   = 'Short text';
		$result = $this->gridRenderer->excerpt($text, 100);

		$this->assertIsString($result);
		$this->assertStringContainsString('<p', $result);
		$this->assertStringContainsString('class="cms-excerpt"', $result);
		$this->assertStringContainsString('Short text', $result);
	}

	public function testExcerptWithNullText(): void
	{
		$result = $this->gridRenderer->excerpt(null, 50);

		$this->assertIsString($result);
		$this->assertStringContainsString('<p', $result);
		$this->assertStringContainsString('class="cms-excerpt"', $result);
	}

	public function testExcerptWithEmptyText(): void
	{
		$result = $this->gridRenderer->excerpt('', 50);

		$this->assertIsString($result);
		$this->assertStringContainsString('<p', $result);
		$this->assertStringContainsString('class="cms-excerpt"', $result);
	}

	public function testPriceWithNumericValue(): void
	{
		$result = $this->gridRenderer->price(19.99);

		$this->assertIsString($result);
		$this->assertStringContainsString('<span', $result);
		$this->assertStringContainsString('class="cms-price"', $result);
		$this->assertStringContainsString('19.99', $result);
		$this->assertStringContainsString('$', $result);
	}

	public function testPriceWithCustomCurrency(): void
	{
		$result = $this->gridRenderer->price(25.50, '€');

		$this->assertIsString($result);
		$this->assertStringContainsString('<span', $result);
		$this->assertStringContainsString('class="cms-price"', $result);
		$this->assertStringContainsString('25.50', $result);
		$this->assertStringContainsString('€', $result);
	}

	public function testPriceWithAppendFormat(): void
	{
		$result = $this->gridRenderer->price(100, 'USD', 'append');

		$this->assertIsString($result);
		$this->assertStringContainsString('<span', $result);
		$this->assertStringContainsString('class="cms-price"', $result);
		$this->assertStringContainsString('100', $result);
		$this->assertStringContainsString('USD', $result);
	}

	public function testPriceWithNoFormatting(): void
	{
		$result = $this->gridRenderer->price(50, '$', 'none');

		$this->assertIsString($result);
		$this->assertStringContainsString('<span', $result);
		$this->assertStringContainsString('class="cms-price"', $result);
		$this->assertStringContainsString('50', $result);
	}

	public function testPriceWithZeroValue(): void
	{
		$result = $this->gridRenderer->price(0);

		$this->assertIsString($result);
		$this->assertStringContainsString('<span', $result);
		$this->assertStringContainsString('class="cms-price"', $result);
		$this->assertStringContainsString('0', $result);
	}

	public function testPriceWithInvalidValue(): void
	{
		$result = $this->gridRenderer->price('invalid');

		// Invalid strings are converted to 0.00 by the price filter
		$this->assertIsString($result);
		$this->assertStringContainsString('<span', $result);
		$this->assertStringContainsString('class="cms-price"', $result);
		$this->assertStringContainsString('$0.00', $result);
	}

	public function testPriceWithNullValue(): void
	{
		$result = $this->gridRenderer->price(null);

		// Should handle null gracefully
		$this->assertIsString($result);
	}

	public function testAllMethodsReturnStrings(): void
	{
		// Test that all methods return strings as expected
		$this->assertIsString($this->gridRenderer->meta('test'));
		$this->assertIsString($this->gridRenderer->tags(['test']));
		$this->assertIsString($this->gridRenderer->date('2024-01-01'));
		$this->assertIsString($this->gridRenderer->excerpt('test'));
		$this->assertIsString($this->gridRenderer->price(10));
	}

	public function testMethodsWithEdgeCases(): void
	{
		// Test various edge cases
		$this->assertEquals('', $this->gridRenderer->meta(''));
		$this->assertEquals('', $this->gridRenderer->tags([]));
		$this->assertEquals('', $this->gridRenderer->date(''));
		$this->assertIsString($this->gridRenderer->excerpt(''));
		$this->assertIsString($this->gridRenderer->price(''));
	}
}
