<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Service\ContentRenderer;
use TotalCMS\Domain\Twig\Markdown\TiptapToMarkdownConverter;

final class ContentRendererTest extends TestCase
{
	private ContentRenderer $renderer;

	protected function setUp(): void
	{
		// Real converter — it's pure and the format-routing logic of
		// ContentRenderer is what we want to verify, not a mock indirection.
		$this->renderer = new ContentRenderer(new TiptapToMarkdownConverter());
	}

	// ─── markdown (the default) ──────────────────────────────────────────────

	public function testMarkdownFormatConvertsHtmlToMarkdown(): void
	{
		$result = trim($this->renderer->render(
			value: '<p>Hello <strong>world</strong>.</p>',
			format: 'markdown',
		));

		$this->assertSame('Hello **world**.', $result);
	}

	public function testMarkdownIsTheDefaultFormat(): void
	{
		$result = trim($this->renderer->render(value: '<p>Hi.</p>'));
		$this->assertSame('Hi.', $result);
	}

	// ─── html (pass-through, since input is already HTML) ────────────────────

	public function testHtmlFormatPassesInputThrough(): void
	{
		// Input is already HTML — no transformation needed. The plan once
		// proposed markdown→HTML via Parsedown, but that only makes sense
		// if input was markdown. Our input is HTML, so html mode is identity.
		$html   = '<p>Hello <strong>world</strong>.</p>';
		$result = $this->renderer->render(value: $html, format: 'html');

		$this->assertSame($html, $result);
	}

	// ─── text (strip everything to plain text) ───────────────────────────────

	public function testTextFormatStripsHtmlTags(): void
	{
		$result = trim($this->renderer->render(
			value: '<p>Hello <strong>world</strong>.</p>',
			format: 'text',
		));

		$this->assertSame('Hello world.', $result);
	}

	public function testTextFormatDecodesHtmlEntities(): void
	{
		// HTML entities (e.g. &amp; &lt;) must decode in text mode — the
		// agent shouldn't see "Tom &amp; Jerry" when the actual content
		// is "Tom & Jerry".
		$result = trim($this->renderer->render(
			value: '<p>Tom &amp; Jerry &lt;3</p>',
			format: 'text',
		));

		$this->assertSame('Tom & Jerry <3', $result);
	}

	public function testTextFormatCollapsesParagraphsToNewlines(): void
	{
		// Multiple paragraphs should produce readable plain text, not
		// "FirstSecond" jammed together.
		$result = $this->renderer->render(
			value: '<p>First paragraph.</p><p>Second paragraph.</p>',
			format: 'text',
		);

		$this->assertStringContainsString('First paragraph.', $result);
		$this->assertStringContainsString('Second paragraph.', $result);
		$this->assertStringNotContainsString('First paragraph.Second', $result);
	}

	// ─── Edge cases ──────────────────────────────────────────────────────────

	public function testEmptyStringReturnsEmptyInAllFormats(): void
	{
		foreach (['markdown', 'html', 'text'] as $format) {
			$this->assertSame('', $this->renderer->render(value: '', format: $format));
		}
	}

	public function testUnknownFormatFallsBackToMarkdown(): void
	{
		// Defensive: agent passing a typo'd format shouldn't crash. Fall back
		// to the default (markdown) silently — better than erroring.
		$result = trim($this->renderer->render(
			value: '<p><strong>bold</strong></p>',
			format: 'invalid-format',
		));

		$this->assertSame('**bold**', $result);
	}

	// ─── localizedstyledtext (locale-keyed object values) ───────────────────

	public function testLocalizedstyledtextValueRendersEachLocale(): void
	{
		// Localized rich-text fields store as {en_US: "<html>", de: "<html>", ...}.
		// Each locale's HTML should convert per the requested format; the locale
		// keys are preserved so the agent sees the same shape it would if it
		// fetched the raw object.
		$value = [
			'en_US' => '<p>Hello <strong>world</strong>.</p>',
			'de'    => '<p>Hallo <strong>Welt</strong>.</p>',
		];

		$result = $this->renderer->render($value, 'markdown');

		$this->assertIsArray($result);
		$this->assertSame('Hello **world**.', trim($result['en_US']));
		$this->assertSame('Hallo **Welt**.', trim($result['de']));
	}

	public function testLocalizedstyledtextHonorsHtmlAndTextFormats(): void
	{
		$value = ['en_US' => '<p>Hello <strong>world</strong>.</p>'];

		$html = $this->renderer->render($value, 'html');
		$this->assertSame($value['en_US'], $html['en_US']);

		$text = $this->renderer->render($value, 'text');
		$this->assertSame('Hello world.', trim($text['en_US']));
	}

	public function testLocalizedValueWithNonStringLocaleValuePassesThrough(): void
	{
		// Defensive: a malformed locale entry (e.g. accidentally an array or
		// null after a migration) shouldn't crash the renderer. Pass it
		// through verbatim — the AI sees the broken data and can flag it.
		$value = [
			'en_US' => '<p>Good.</p>',
			'broken' => null,
			'also_broken' => ['unexpected'],
		];

		$result = $this->renderer->render($value, 'markdown');

		$this->assertSame('Good.', trim($result['en_US']));
		$this->assertNull($result['broken']);
		$this->assertSame(['unexpected'], $result['also_broken']);
	}

	public function testPlainStringWithNoHtmlPassesThroughInAllFormats(): void
	{
		// Some styledtext fields may contain raw text (e.g. legacy data, or
		// a one-line snippet). All three formats should handle this gracefully.
		$plain = 'Just plain text.';

		$this->assertSame($plain, trim($this->renderer->render($plain, 'markdown')));
		$this->assertSame($plain, $this->renderer->render($plain, 'html'));
		$this->assertSame($plain, trim($this->renderer->render($plain, 'text')));
	}
}
