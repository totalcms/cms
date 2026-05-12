<?php

declare(strict_types=1);

namespace Tests\Unit\Property;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Property\Data\StringData;
use TotalCMS\Support\Config;

#[CoversClass(StringData::class)]
final class StringDataTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		// Reset config for each test
		Config::init();
	}

	public function testDetectsHtmlContentCorrectly(): void
	{
		$data = new StringData('<p>Hello</p>', ['htmlclean' => false]);
		$this->assertTrue($data->containsHTML());
	}

	public function testDetectsPlainTextCorrectly(): void
	{
		$data = new StringData('Hello World', ['htmlclean' => false]);
		$this->assertFalse($data->containsHTML());
	}

	public function testDetectsSelfClosingTags(): void
	{
		$data = new StringData('Hello <br/> World', ['htmlclean' => false]);
		$this->assertTrue($data->containsHTML());
	}

	public function testDetectsEmptyTags(): void
	{
		$data = new StringData('Hello <span></span> World', ['htmlclean' => false]);
		$this->assertTrue($data->containsHTML());
	}

	public function testHandlesEntitiesVsHtmlTags(): void
	{
		$plainText = new StringData('Hello &lt;script&gt; World', ['htmlclean' => false]);
		$this->assertFalse($plainText->containsHTML());

		$htmlText = new StringData('Hello <script> World', ['htmlclean' => false]);
		$this->assertTrue($htmlText->containsHTML());
	}

	public function testSanitizesDangerousScripts(): void
	{
		$data = new StringData('<script>alert("xss")</script>Hello');
		$this->assertStringNotContainsString('<script>', $data->text);
		$this->assertSame('Hello', $data->text);
	}

	public function testSanitizesEventHandlers(): void
	{
		$data = new StringData('<div onclick="alert(1)">Hello</div>');
		$this->assertStringNotContainsString('onclick', $data->text);
		$this->assertStringContainsString('Hello', $data->text);
	}

	public function testSanitizesDangerousProtocols(): void
	{
		$data = new StringData('<a href="javascript:alert(1)">Link</a>');
		$this->assertStringNotContainsString('javascript:', $data->text);
		$this->assertStringContainsString('Link', $data->text);
	}

	public function testRemovesDangerousTags(): void
	{
		$data = new StringData('<object>Hello</object><embed>World</embed>');
		$this->assertStringNotContainsString('<object>', $data->text);
		$this->assertStringNotContainsString('<embed>', $data->text);
	}

	public function testPreservesSafeHtml(): void
	{
		$data = new StringData('<p><strong>Hello</strong> <em>World</em></p>');
		$this->assertStringContainsString('<p>', $data->text);
		$this->assertStringContainsString('<strong>', $data->text);
		$this->assertStringContainsString('<em>', $data->text);
	}

	public function testRespectsHtmlcleanFieldSettingWhenDisabled(): void
	{
		$dangerousHTML = '<script>alert("xss")</script>Hello';
		$data          = new StringData($dangerousHTML, ['htmlclean' => false]);
		$this->assertSame($dangerousHTML, $data->text);
	}

	public function testSkipsSanitizationForPlainText(): void
	{
		$plainText = 'Hello World';
		$data      = new StringData($plainText);
		$this->assertSame($plainText, $data->text);
	}

	public function testTransformsToStringCorrectly(): void
	{
		$data = new StringData('Hello World');
		$this->assertSame('Hello World', $data->transform());
	}

	public function testCastsToStringCorrectly(): void
	{
		$data = new StringData('Hello World');
		$this->assertSame('Hello World', (string)$data);
	}

	public function testHandlesEmptyStrings(): void
	{
		$data = new StringData('');
		$this->assertSame('', $data->transform());
		$this->assertSame('', (string)$data);
		$this->assertFalse($data->containsHTML());
	}

	public function testPreventsBasicXssAttacks(): void
	{
		$attacks = [
			'<script>alert("xss")</script>',
			'<img src="x" onerror="alert(1)">',
			'<div onclick="alert(1)">Click me</div>',
			'<iframe src="javascript:alert(1)"></iframe>',
			'<object data="javascript:alert(1)"></object>',
		];

		foreach ($attacks as $attack) {
			$data = new StringData($attack);
			$this->assertStringNotContainsString('alert', $data->text);
			$this->assertStringNotContainsString('javascript:', $data->text);
		}
	}

	public function testPreventsEncodedXssAttacks(): void
	{
		$data = new StringData('<img src="x" onerror="&#97;&#108;&#101;&#114;&#116;&#40;&#49;&#41;">');
		$this->assertStringNotContainsString('onerror', $data->text);
	}

	public function testPreventsCssBasedXss(): void
	{
		$data = new StringData('<div style="background-image: url(javascript:alert(1))">Hello</div>');
		$this->assertStringNotContainsString('javascript:', $data->text);
	}

	public function testPreventsDataUriXss(): void
	{
		$data = new StringData('<img src="data:text/html,<script>alert(1)</script>">');
		$this->assertStringNotContainsString('data:text/html', $data->text);
	}

	public function testHandlesMalformedHtml(): void
	{
		$data = new StringData('<div><p>Unclosed tags<div>');
		$this->assertTrue($data->containsHTML());
		$this->assertStringContainsString('Unclosed tags', $data->text);
	}

	public function testHandlesNestedDangerousContent(): void
	{
		$data = new StringData('<div><script>alert(1)</script><p>Safe content</p></div>');
		$this->assertStringNotContainsString('<script>', $data->text);
		$this->assertStringContainsString('Safe content', $data->text);
	}

	public function testHandlesMixedContentTypes(): void
	{
		$data = new StringData('Plain text <strong>bold</strong> &amp; entities');
		$this->assertTrue($data->containsHTML());
		$this->assertStringContainsString('<strong>', $data->text);
		$this->assertStringContainsString('&amp;', $data->text);
	}

	public function testHandlesVeryLongContent(): void
	{
		$longContent = str_repeat('<p>Safe content</p>', 1000);
		$data        = new StringData($longContent);
		$this->assertTrue($data->containsHTML());
		$this->assertSame(1000, substr_count($data->text, '<p>'));
	}

	public function testHandlesUnicodeContent(): void
	{
		$data = new StringData('<p>Hello 世界 🌍</p>');
		$this->assertTrue($data->containsHTML());
		$this->assertStringContainsString('世界', $data->text);
		$this->assertStringContainsString('🌍', $data->text);
	}

	public function testPreventsScriptInjectionInAttributes(): void
	{
		$data = new StringData('<img src="valid.jpg" title="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;">');
		$this->assertStringNotContainsString('<script>', $data->text);
	}

	public function testPreventsEventHandlerInjection(): void
	{
		$events = ['onclick', 'onload', 'onerror', 'onmouseover', 'onfocus'];
		foreach ($events as $event) {
			$data = new StringData("<div {$event}=\"alert(1)\">Test</div>");
			$this->assertStringNotContainsString($event, $data->text);
		}
	}

	public function testPreventsFormBasedAttacks(): void
	{
		$data = new StringData('<form><input type="submit" value="Click me"></form>');
		$this->assertStringNotContainsString('<form>', $data->text);
		$this->assertStringNotContainsString('<input>', $data->text);
	}

	public function testPreventsLinkBasedAttacks(): void
	{
		$data = new StringData('<link rel="stylesheet" href="javascript:alert(1)">');
		$this->assertStringNotContainsString('<link>', $data->text);
	}
}
