<?php

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use TotalCMS\Utils\HTMLSanitizer;
use TotalCMS\Utils\HTMLSanitizerConfig;

/**
 * Test HTML Sanitization Security.
 *
 * @covers \TotalCMS\Utils\HTMLSanitizer
 * @covers \TotalCMS\Utils\HTMLSanitizerConfig
 */
final class HTMLSanitizerTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
	}

	public function testSanitizeRichContentAllowsSafeHTML(): void
	{
		$input  = '<p>This is <strong>bold</strong> and <em>italic</em> text.</p>';
		$result = HTMLSanitizer::sanitizeRichContent($input);

		$this->assertEquals($input, $result);
	}

	public function testSanitizeRichContentRemovesScriptTags(): void
	{
		$input  = '<p>Safe content</p><script>alert("XSS")</script>';
		$result = HTMLSanitizer::sanitizeRichContent($input);

		$this->assertStringContainsString('<p>Safe content</p>', $result);
		$this->assertStringNotContainsString('<script>', $result);
		$this->assertStringNotContainsString('alert("XSS")', $result);
	}

	public function testSanitizeRichContentRemovesJavaScriptEvents(): void
	{
		$input  = '<p onclick="alert(\'XSS\')">Click me</p>';
		$result = HTMLSanitizer::sanitizeRichContent($input);

		$this->assertStringContainsString('<p>Click me</p>', $result);
		$this->assertStringNotContainsString('onclick', $result);
		$this->assertStringNotContainsString('alert', $result);
	}

	public function testSanitizeRichContentRemovesStyleWithJavaScript(): void
	{
		$input  = '<div style="background: url(javascript:alert(\'XSS\'))">Content</div>';
		$result = HTMLSanitizer::sanitizeRichContent($input);

		$this->assertStringContainsString('Content', $result);
		$this->assertStringNotContainsString('javascript:', $result);
		$this->assertStringNotContainsString('alert', $result);
	}

	public function testSanitizeRichContentAllowsSafeCSS(): void
	{
		// Test that safe CSS is preserved when allowed_css_properties is not empty
		$input  = '<p style="color: red; font-size: 14px;">Styled text</p>';
		$config = ['allowed_css_properties' => ['color', 'font-size']];
		$result = HTMLSanitizer::sanitizeRichContent($input, $config);

		// Current implementation only preserves styles when allowed_css_properties is not empty
		$this->assertStringContainsString('style=', $result);
		$this->assertStringContainsString('Styled text', $result);

		// Test that CSS is removed when allowed_css_properties is empty
		$configEmpty = ['allowed_css_properties' => []];
		$resultEmpty = HTMLSanitizer::sanitizeRichContent($input, $configEmpty);
		$this->assertStringNotContainsString('style=', $resultEmpty);
		$this->assertStringContainsString('Styled text', $resultEmpty);
	}

	public function testSanitizeRichContentHandlesNestedTags(): void
	{
		$input  = '<div><p>Paragraph with <a href="http://example.com">link</a> and <strong>bold <em>italic</em></strong></p></div>';
		$result = HTMLSanitizer::sanitizeRichContent($input);

		$this->assertStringContainsString('<div>', $result);
		$this->assertStringContainsString('<p>', $result);
		$this->assertStringContainsString('<a href="http://example.com">', $result);
		$this->assertStringContainsString('<strong>', $result);
		$this->assertStringContainsString('<em>', $result);
	}

	public function testSanitizeRichContentRemovesDataAttributes(): void
	{
		$input  = '<div data-malicious="javascript:alert(1)" data-safe="value">Content</div>';
		$result = HTMLSanitizer::sanitizeRichContent($input);

		$this->assertStringContainsString('Content', $result);
		$this->assertStringNotContainsString('data-malicious', $result);
		$this->assertStringNotContainsString('javascript:', $result);
	}

	public function testSanitizeRichContentHandlesIframes(): void
	{
		// Allowed iframe domain
		$input  = '<iframe src="https://www.youtube.com/embed/12345" width="560" height="315"></iframe>';
		$result = HTMLSanitizer::sanitizeRichContent($input);

		$this->assertStringContainsString('<iframe', $result);
		$this->assertStringContainsString('youtube.com', $result);

		// Disallowed iframe domain
		$input  = '<iframe src="https://malicious.com/evil.html"></iframe>';
		$result = HTMLSanitizer::sanitizeRichContent($input);

		$this->assertStringNotContainsString('malicious.com', $result);
	}

	public function testSanitizeStrictContentRemovesMoreTags(): void
	{
		$input  = '<p>Text</p><div>More text</div><script>alert("XSS")</script>';
		$result = HTMLSanitizer::sanitizeStrictContent($input);

		$this->assertStringContainsString('<p>Text</p>', $result);
		$this->assertStringNotContainsString('<div>', $result);
		$this->assertStringNotContainsString('<script>', $result);
	}

	public function testSanitizeStrictContentRemovesAllStyles(): void
	{
		$input  = '<p style="color: red;">Styled text</p>';
		$result = HTMLSanitizer::sanitizeStrictContent($input);

		$this->assertEquals('<p>Styled text</p>', $result);
	}

	public function testSanitizeStrictContentAllowsBasicFormatting(): void
	{
		$input  = '<p>This has <strong>bold</strong>, <em>italic</em>, and <a href="http://example.com">links</a>.</p>';
		$result = HTMLSanitizer::sanitizeStrictContent($input);

		$this->assertStringContainsString('<strong>bold</strong>', $result);
		$this->assertStringContainsString('<em>italic</em>', $result);
		$this->assertStringContainsString('<a href="http://example.com">links</a>', $result);
	}

	public function testSanitizeWithCustomConfig(): void
	{
		$config = [
			'allowed_tags'           => ['p', 'br'],
			'allowed_css_properties' => [],
			'allowed_iframe_domains' => [],
		];

		$input  = '<p>Paragraph</p><div>Div content</div><strong>Bold</strong>';
		$result = HTMLSanitizer::sanitizeRichContent($input, $config);

		$this->assertStringContainsString('<p>Paragraph</p>', $result);
		$this->assertStringNotContainsString('<div>', $result);
		$this->assertStringNotContainsString('<strong>', $result);
		$this->assertStringContainsString('Div content', $result); // Content preserved, tags removed
		$this->assertStringContainsString('Bold', $result);
	}

	public function testEmptyInput(): void
	{
		$result = HTMLSanitizer::sanitizeRichContent('');
		$this->assertEquals('', $result);

		$result = HTMLSanitizer::sanitizeStrictContent('');
		$this->assertEquals('', $result);
	}

	public function testPlainTextInput(): void
	{
		$input  = 'This is just plain text with no HTML.';
		$result = HTMLSanitizer::sanitizeRichContent($input);

		$this->assertEquals($input, $result);
	}

	public function testMalformedHTML(): void
	{
		$input  = '<p>Unclosed paragraph<div>Nested without closing<strong>Bold';
		$result = HTMLSanitizer::sanitizeRichContent($input);

		// Our simple sanitizer preserves content but doesn't auto-fix HTML structure
		$this->assertStringContainsString('Unclosed paragraph', $result);
		$this->assertStringContainsString('Nested without closing', $result);
		$this->assertStringContainsString('Bold', $result);

		// Note: Our simple sanitizer doesn't auto-close tags like more complex sanitizers would
		// For production use with malformed HTML, consider additional HTML validation
	}

	public function testSQLInjectionAttempts(): void
	{
		$input  = '<p>Text</p><!-- \'; DROP TABLE users; -->';
		$result = HTMLSanitizer::sanitizeRichContent($input);

		$this->assertStringContainsString('<p>Text</p>', $result);
		$this->assertStringNotContainsString('DROP TABLE', $result);
	}

	public function testXSSAttackVectors(): void
	{
		$xssVectors = [
			'<script>alert("XSS")</script>',
			'<img src="x" onerror="alert(\'XSS\')">',
			'<svg onload="alert(1)">',
			'<iframe src="javascript:alert(\'XSS\')"></iframe>',
			'<object data="javascript:alert(\'XSS\')"></object>',
			'<embed src="javascript:alert(\'XSS\')">',
			'<link rel="stylesheet" href="javascript:alert(\'XSS\')">',
			'<style>@import "javascript:alert(\'XSS\')"</style>',
			'<div style="background: url(javascript:alert(\'XSS\'))">',
			'<a href="javascript:alert(\'XSS\')">Click</a>',
			'<form action="javascript:alert(\'XSS\')">',
			'<body onload="alert(\'XSS\')">',
			'<meta http-equiv="refresh" content="0;url=javascript:alert(\'XSS\')">',
		];

		foreach ($xssVectors as $vector) {
			$result = HTMLSanitizer::sanitizeRichContent($vector);

			$this->assertStringNotContainsString('alert', $result, "XSS vector was not properly sanitized: $vector");
			$this->assertStringNotContainsString('javascript:', $result, "JavaScript protocol not removed: $vector");
		}
	}

	public function testHTMLEntitiesHandling(): void
	{
		$input  = '<p>&lt;script&gt;alert("XSS")&lt;/script&gt;</p>';
		$result = HTMLSanitizer::sanitizeRichContent($input);

		// Should preserve the entities and not execute the decoded script
		$this->assertStringContainsString('&lt;script&gt;', $result);
		$this->assertStringNotContainsString('<script>', $result);
	}

	public function testUnicodeHandling(): void
	{
		$input  = '<p>Unicode: 你好世界 🌍 Héllo Wörld</p>';
		$result = HTMLSanitizer::sanitizeRichContent($input);

		$this->assertEquals($input, $result);
	}

	public function testLargeInputHandling(): void
	{
		// Test with large input to ensure performance and memory handling
		$largeContent = str_repeat('<p>This is a paragraph with some content. </p>', 1000);
		$input        = '<div>' . $largeContent . '</div>';

		$result = HTMLSanitizer::sanitizeRichContent($input);

		$this->assertStringContainsString('<div>', $result);
		$this->assertStringContainsString('<p>This is a paragraph', $result);
		$this->assertStringContainsString('</div>', $result);
	}

	public function testHTMLSanitizerConfig(): void
	{
		// Test that config constants are properly defined
		$this->assertIsArray(HTMLSanitizerConfig::RICH_CONTENT_ALLOWED_TAGS);
		$this->assertIsArray(HTMLSanitizerConfig::STRICT_CONTENT_ALLOWED_TAGS);
		$this->assertIsArray(HTMLSanitizerConfig::ALLOWED_CSS_PROPERTIES);
		$this->assertIsArray(HTMLSanitizerConfig::ALLOWED_IFRAME_DOMAINS);
		$this->assertIsArray(HTMLSanitizerConfig::DANGEROUS_MIME_TYPES);

		// Test that basic tags are included
		$this->assertContains('p', HTMLSanitizerConfig::RICH_CONTENT_ALLOWED_TAGS);
		$this->assertContains('strong', HTMLSanitizerConfig::RICH_CONTENT_ALLOWED_TAGS);
		$this->assertContains('em', HTMLSanitizerConfig::RICH_CONTENT_ALLOWED_TAGS);

		// Test that script is not in allowed tags
		$this->assertNotContains('script', HTMLSanitizerConfig::RICH_CONTENT_ALLOWED_TAGS);
		$this->assertNotContains('script', HTMLSanitizerConfig::STRICT_CONTENT_ALLOWED_TAGS);

		// Test that strict mode has fewer tags than rich mode
		$this->assertLessThan(
			count(HTMLSanitizerConfig::RICH_CONTENT_ALLOWED_TAGS),
			count(HTMLSanitizerConfig::STRICT_CONTENT_ALLOWED_TAGS)
		);
	}

	public function testEdgeCasesAndCornerCases(): void
	{
		// Test with only whitespace
		$result = HTMLSanitizer::sanitizeRichContent('   ');
		$this->assertEquals('   ', $result);

		// Test with only HTML entities
		$result = HTMLSanitizer::sanitizeRichContent('&amp; &lt; &gt; &quot;');
		$this->assertEquals('&amp; &lt; &gt; &quot;', $result);

		// Test with deeply nested tags
		$input  = '<div><p><strong><em><span>Deep nesting</span></em></strong></p></div>';
		$result = HTMLSanitizer::sanitizeRichContent($input);
		$this->assertStringContainsString('Deep nesting', $result);

		// Test with mixed case tags
		$input  = '<P>Mixed <STRONG>case</STRONG> tags</P>';
		$result = HTMLSanitizer::sanitizeRichContent($input);
		$this->assertStringContainsString('Mixed', $result);
		$this->assertStringContainsString('case', $result);
		$this->assertStringContainsString('tags', $result);
	}

	/**
	 * Test specific to CMS use case: content with embedded media.
	 */
	public function testCMSContentScenarios(): void
	{
		// Blog post with various formatting
		$blogPost = '<h2>Blog Post Title</h2><p>Introduction paragraph with <a href="http://example.com">external link</a>.</p><blockquote><p>This is a quote from someone important.</p></blockquote><ul><li>List item 1</li><li>List item 2</li></ul><p>Conclusion with <strong>emphasis</strong>.</p>';

		$result = HTMLSanitizer::sanitizeRichContent($blogPost);

		$this->assertStringContainsString('<h2>Blog Post Title</h2>', $result);
		$this->assertStringContainsString('<blockquote>', $result);
		$this->assertStringContainsString('<ul>', $result);
		$this->assertStringContainsString('<li>', $result);
		$this->assertStringContainsString('<a href="http://example.com">', $result);

		// Gallery description with styling
		$gallery = '<div class="gallery-description"><p style="text-align: center; color: #666;">Photo gallery from our recent trip.</p></div>';

		$result = HTMLSanitizer::sanitizeRichContent($gallery);

		// The simple sanitizer doesn't preserve CSS, so just check content is preserved
		$this->assertStringContainsString('Photo gallery', $result);
	}

	/**
	 * Test performance with realistic content sizes.
	 */
	public function testPerformanceWithRealisticContent(): void
	{
		// Simulate a large article (approximately 10KB of HTML)
		$article = '<h1>Article Title</h1>';
		for ($i = 0; $i < 50; $i++) {
			$article .= '<p>This is paragraph ' . $i . ' with some <strong>bold text</strong> and <em>italic text</em>. ';
			$article .= 'It also contains <a href="http://example.com/link' . $i . '">a link</a> and some other content to make it realistic.</p>';
		}
		$article .= '<ul>';
		for ($i = 0; $i < 20; $i++) {
			$article .= '<li>List item ' . $i . '</li>';
		}
		$article .= '</ul>';

		$startTime = microtime(true);
		$result    = HTMLSanitizer::sanitizeRichContent($article);
		$endTime   = microtime(true);

		$processingTime = $endTime - $startTime;

		// Should process in reasonable time (less than 1 second for 10KB)
		$this->assertLessThan(1.0, $processingTime, 'Sanitization took too long');

		// Should preserve basic structure
		$this->assertStringContainsString('<h1>Article Title</h1>', $result);
		$this->assertStringContainsString('<p>This is paragraph', $result);
		$this->assertStringContainsString('<ul>', $result);
		$this->assertStringContainsString('<li>List item', $result);
	}
}
