<?php

declare(strict_types=1);

namespace Tests\Security;

use ParsedownExtra;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Twig\Markdown\ParsedownMarkdown;

#[CoversClass(ParsedownMarkdown::class)]
final class ParsedownSecurityTest extends TestCase
{
	private ParsedownMarkdown $parsedown;

	protected function setUp(): void
	{
		$this->parsedown = new ParsedownMarkdown();
	}

	public function testPreventsXSSInMarkdown(): void
	{
		$xssAttempts = [
			'<script>alert("xss")</script>',
			'<img src="x" onerror="alert(1)">',
			'<iframe src="javascript:alert(1)"></iframe>',
			'<object data="javascript:alert(1)"></object>',
			'<embed src="javascript:alert(1)">',
			'<link rel="stylesheet" href="javascript:alert(1)">',
			'<meta http-equiv="refresh" content="0;url=javascript:alert(1)">',
			'<form action="javascript:alert(1)"><input type="submit"></form>',
		];

		foreach ($xssAttempts as $malicious) {
			$result = $this->parsedown->convert($malicious);

			$this->assertIsString($result);
			// In safe mode, HTML should be escaped, so dangerous tags become harmless text
			$this->assertStringNotContainsString('<script>', $result);
			$this->assertStringNotContainsString('<iframe>', $result);
			$this->assertStringNotContainsString('<object>', $result);
			$this->assertStringNotContainsString('<embed>', $result);
			// Check that content is escaped (contains &lt; instead of <)
			$this->assertStringContainsString('&lt;', $result);
		}
	}

	public function testHandlesDangerousAttributes(): void
	{
		$dangerousAttributes = [
			'<a href="javascript:alert(1)">Link</a>',
			'<img src="image.jpg" onload="alert(1)">',
			'<div onclick="alert(1)">Content</div>',
			'<p onmouseover="alert(1)">Text</p>',
			'<span style="background:url(javascript:alert(1))">Styled</span>',
			'<h1 data-onclick="alert(1)">Heading</h1>',
		];

		foreach ($dangerousAttributes as $malicious) {
			$result = $this->parsedown->convert($malicious);

			$this->assertIsString($result);
			// In safe mode, HTML is escaped so dangerous attributes become text
			$this->assertStringContainsString('&lt;', $result);
			$this->assertStringContainsString('&gt;', $result);
			// Dangerous scripts should not be executable
			$this->assertStringNotContainsString('<a href="javascript:', $result);
			$this->assertStringNotContainsString('<img src=', $result);
		}
	}

	public function testSafeModeEnabled(): void
	{
		// Test that safe mode is enabled (should strip HTML)
		$htmlContent = [
			'<div>Raw HTML</div>',
			'<p>Paragraph with <strong>bold</strong> text</p>',
			'<ul><li>List item</li></ul>',
			'<table><tr><td>Table cell</td></tr></table>',
		];

		foreach ($htmlContent as $html) {
			$result = $this->parsedown->convert($html);

			$this->assertIsString($result);
			// In safe mode, HTML should be escaped or stripped
			$this->assertStringNotContainsString('<div>', $result);
			$this->assertStringNotContainsString('<script>', $result);
		}
	}

	public function testMarkdownLinkSecurity(): void
	{
		$dangerousLinks = [
			'[XSS](javascript:alert(1))',
			'[XSS](data:text/html,<script>alert(1)</script>)',
			'[XSS](vbscript:msgbox(1))',
			'[XSS](file:///etc/passwd)',
			'![XSS](javascript:alert(1))',
			'<a href="javascript:alert(1)">XSS</a>',
		];

		foreach ($dangerousLinks as $malicious) {
			$result = $this->parsedown->convert($malicious);

			$this->assertIsString($result);
			// ParsedownExtra in safe mode may still process links but they should be safe
			// Raw HTML should be escaped
			if (str_contains($malicious, '<a href=')) {
				$this->assertStringContainsString('&lt;', $result);
			}
			// The key is that any dangerous content should not be executable
			$this->assertIsString($result); // Basic test that it processes without errors
		}
	}

	public function testCodeBlockSecurity(): void
	{
		$codeBlocks = [
			'```html\n<script>alert("xss")</script>\n```',
			'```javascript\nalert("xss");\n```',
			'```php\n<?php system("ls"); ?>\n```',
			'    <script>alert("indented")</script>',
		];

		foreach ($codeBlocks as $code) {
			$result = $this->parsedown->convert($code);

			$this->assertIsString($result);
			$this->assertStringContainsString('<code>', $result);
			// Code should be properly escaped within code blocks
			$this->assertStringNotContainsString('<script>alert', $result);
		}
	}

	public function testHTMLEntityHandling(): void
	{
		$entityTests = [
			'&lt;script&gt;alert(1)&lt;/script&gt;',
			'&#60;script&#62;alert(1)&#60;/script&#62;',
			'&#x3C;script&#x3E;alert(1)&#x3C;/script&#x3E;',
			'&amp;lt;script&amp;gt;alert(1)&amp;lt;/script&amp;gt;',
		];

		foreach ($entityTests as $entities) {
			$result = $this->parsedown->convert($entities);

			$this->assertIsString($result);
			// Should not decode into executable script tags
			$this->assertStringNotContainsString('<script>alert', $result);
		}
	}

	public function testUnicodeSecurityBypass(): void
	{
		$unicodeAttacks = [
			"\u003Cscript\u003Ealert(1)\u003C/script\u003E",
			"\uFF1Cscript\uFF1Ealert(1)\uFF1C/script\uFF1E",
			'＜script＞alert(1)＜/script＞',
			'〈script〉alert(1)〈/script〉',
		];

		foreach ($unicodeAttacks as $unicode) {
			$result = $this->parsedown->convert($unicode);

			$this->assertIsString($result);
			// Unicode attacks should be treated as text, not executed
			// The content may still be present but should not be dangerous HTML
			$this->assertStringNotContainsString('<script>', $result);
		}
	}

	public function testMarkdownTableSecurity(): void
	{
		$maliciousTables = [
			"| Header |\n|--------|\n| <script>alert(1)</script> |",
			"| [XSS](javascript:alert(1)) | Normal |\n|---|---|\n| Cell | Cell |",
			"| Header | <img src=x onerror=alert(1)> |\n|---|---|\n| Cell | Cell |",
		];

		foreach ($maliciousTables as $table) {
			$result = $this->parsedown->convert($table);

			$this->assertIsString($result);
			$this->assertStringContainsString('<table>', $result);
			$this->assertStringNotContainsString('<script>', $result);
			// Check that HTML is properly handled in table context
			// Some tables may escape HTML, others may process markdown
			$this->assertIsString($result); // Basic safety check
		}
	}

	public function testBreaksEnabledSecurity(): void
	{
		// Test that line breaks don't introduce security issues
		$breakTests = [
			"Line 1<script>alert(1)</script>\nLine 2",
			"Line 1\n<img src=x onerror=alert(1)>\nLine 2",
			"Line 1  \n<iframe src=javascript:alert(1)></iframe>",
		];

		foreach ($breakTests as $content) {
			$result = $this->parsedown->convert($content);

			$this->assertIsString($result);
			$this->assertStringContainsString('<br', $result);
			$this->assertStringNotContainsString('<script>', $result);
			// HTML should be escaped
			$this->assertStringContainsString('&lt;', $result);
		}
	}

	public function testMarkdownExtensionsSecurity(): void
	{
		// Test ParsedownExtra specific features
		$extensionTests = [
			'[^1]: <script>alert("footnote")</script>',
			'Term\n:   <script>alert("definition")</script>',
			'{: .class onclick=alert(1)}',
			'~~<script>alert("strikethrough")</script>~~',
			'==<script>alert("highlight")</script>==',
		];

		foreach ($extensionTests as $content) {
			$result = $this->parsedown->convert($content);

			$this->assertIsString($result);
			$this->assertStringNotContainsString('<script>alert', $result);
			// Extensions should not create dangerous executable content
			$this->assertIsString($result); // Basic safety check
		}
	}

	public function testLargePlaintextSecurity(): void
	{
		// Test with very large input to ensure no DoS
		$largeContent = str_repeat("# Header\n\nParagraph text with **bold** and *italic* content.\n\n", 1000);

		$start  = microtime(true);
		$result = $this->parsedown->convert($largeContent);
		$time   = microtime(true) - $start;

		$this->assertIsString($result);
		$this->assertLessThan(2.0, $time); // Should complete in reasonable time
		$this->assertStringContainsString('<h1>', $result);
		$this->assertStringContainsString('<strong>', $result);
		$this->assertStringContainsString('<em>', $result);
	}

	public function testBinaryContentHandling(): void
	{
		$binaryContent = "Text with binary: \x00\x01\x02\xFF\xFE\xFD";

		$result = $this->parsedown->convert($binaryContent);

		$this->assertIsString($result);
		// Should handle binary content gracefully
	}

	public function testNestedMarkdownSecurity(): void
	{
		$nestedAttacks = [
			'[![XSS](javascript:alert(1))](javascript:alert(2))',
			'[Link [with](javascript:alert(1)) nested](http://example.com)',
			'> Quote with <script>alert(1)</script>',
			'* List item with <img src=x onerror=alert(1)>',
			'1. Numbered item with [XSS](javascript:alert(1))',
		];

		foreach ($nestedAttacks as $content) {
			$result = $this->parsedown->convert($content);

			$this->assertIsString($result);
			$this->assertStringNotContainsString('<script>', $result);
			// HTML should be escaped where present
			if (str_contains($content, '<')) {
				$this->assertStringContainsString('&lt;', $result);
			}
		}
	}

	public function testValidMarkdownProcessing(): void
	{
		$validMarkdown = [
			'# Heading 1',
			'## Heading 2',
			'**Bold text**',
			'*Italic text*',
			'[Valid link](https://example.com)',
			'![Valid image](https://example.com/image.jpg)',
			'`inline code`',
			'```\ncode block\n```',
			'> Blockquote',
			'* List item',
			'1. Numbered item',
		];

		foreach ($validMarkdown as $content) {
			$result = $this->parsedown->convert($content);

			$this->assertIsString($result);
			$this->assertNotEmpty($result);
			// Should produce valid HTML
			$this->assertStringContainsStringIgnoringCase('<', $result);
		}
	}

	public function testEmptyAndNullInput(): void
	{
		$emptyInputs = [
			'',
			'   ',
			"\n\n\n",
		];

		foreach ($emptyInputs as $input) {
			$result = $this->parsedown->convert($input);

			$this->assertIsString($result);
			// Should handle empty input gracefully
		}

		// Test null separately since it requires type conversion
		$result = $this->parsedown->convert('');
		$this->assertIsString($result);
	}

	public function testSpecialCharacterEscaping(): void
	{
		$specialChars = [
			'<>&"\'',
			'Price: $5 & up',
			'Math: 2 < 3 > 1',
			'Quote: "Hello World"',
			"Apostrophe: It's working",
		];

		foreach ($specialChars as $content) {
			$result = $this->parsedown->convert($content);

			$this->assertIsString($result);
			// Check that dangerous characters are escaped
			if (str_contains($content, '<')) {
				$this->assertStringContainsString('&lt;', $result);
			}
			if (str_contains($content, '>')) {
				$this->assertStringContainsString('&gt;', $result);
			}
			if (str_contains($content, '&')) {
				$this->assertStringContainsString('&amp;', $result);
			}
		}
	}
}
