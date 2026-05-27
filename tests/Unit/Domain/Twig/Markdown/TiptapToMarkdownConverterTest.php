<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Twig\Markdown;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Twig\Markdown\TiptapToMarkdownConverter;

final class TiptapToMarkdownConverterTest extends TestCase
{
	private TiptapToMarkdownConverter $converter;

	protected function setUp(): void
	{
		$this->converter = new TiptapToMarkdownConverter();
	}

	// ─── Plain text + paragraphs ─────────────────────────────────────────────

	public function testEmptyStringPassesThrough(): void
	{
		// Defensive base case — no input, no output. The converter must not
		// throw on empty / whitespace-only content.
		$this->assertSame('', $this->converter->convert(''));
		$this->assertSame('', trim($this->converter->convert('   ')));
	}

	public function testPlainTextNoTags(): void
	{
		// Sometimes styledtext fields end up as raw text without a wrapping
		// <p> — e.g. migrated from a legacy text field. Should round-trip.
		$this->assertSame('Just text.', trim($this->converter->convert('Just text.')));
	}

	public function testSingleParagraph(): void
	{
		$result = $this->converter->convert('<p>Hello world.</p>');
		$this->assertSame('Hello world.', trim($result));
	}

	public function testTwoParagraphsSeparatedByBlankLine(): void
	{
		// Markdown spec: paragraph breaks are blank-line separated. The
		// library handles this; we just verify it.
		$html   = '<p>First paragraph.</p><p>Second paragraph.</p>';
		$result = trim($this->converter->convert($html));

		$this->assertStringContainsString('First paragraph.', $result);
		$this->assertStringContainsString('Second paragraph.', $result);
		$this->assertStringContainsString("\n\n", $result);
	}

	// ─── Inline marks (bold / italic / code / underline / strike) ────────────

	public function testStrongRendersAsBold(): void
	{
		$result = trim($this->converter->convert('<p>This is <strong>bold</strong> text.</p>'));
		$this->assertSame('This is **bold** text.', $result);
	}

	public function testEmRendersAsItalic(): void
	{
		$result = trim($this->converter->convert('<p>This is <em>italic</em> text.</p>'));
		$this->assertSame('This is *italic* text.', $result);
	}

	public function testInlineCodeRendersAsBackticks(): void
	{
		$result = trim($this->converter->convert('<p>Run <code>tcms cache:clear</code> in dev.</p>'));
		$this->assertSame('Run `tcms cache:clear` in dev.', $result);
	}

	public function testNestedInlineMarks(): void
	{
		// Real Tiptap output frequently nests marks — bold+italic, etc.
		$result = trim($this->converter->convert('<p><strong><em>Both</em></strong></p>'));
		$this->assertStringContainsString('Both', $result);
		// Either ***Both*** or **_Both_** is acceptable — both are valid markdown.
		$this->assertMatchesRegularExpression('/[*_]{2,3}Both[*_]{2,3}/', $result);
	}

	// ─── Headings ────────────────────────────────────────────────────────────

	public function testH1ToH6RenderAsAtxHeadings(): void
	{
		// Configured for atx style (# instead of underline) — easier for the
		// agent to read and shorter on the wire.
		for ($i = 1; $i <= 6; $i++) {
			$result   = trim($this->converter->convert("<h{$i}>Heading {$i}</h{$i}>"));
			$expected = str_repeat('#', $i) . " Heading {$i}";
			$this->assertSame($expected, $result, "H{$i} should render as $expected");
		}
	}

	// ─── Lists ───────────────────────────────────────────────────────────────

	public function testUnorderedList(): void
	{
		$html   = '<ul><li>First</li><li>Second</li><li>Third</li></ul>';
		$result = trim($this->converter->convert($html));

		$this->assertStringContainsString('- First', $result);
		$this->assertStringContainsString('- Second', $result);
		$this->assertStringContainsString('- Third', $result);
	}

	public function testOrderedList(): void
	{
		$html   = '<ol><li>First</li><li>Second</li></ol>';
		$result = trim($this->converter->convert($html));

		$this->assertStringContainsString('1. First', $result);
		$this->assertStringContainsString('2. Second', $result);
	}

	// ─── Links + images ──────────────────────────────────────────────────────

	public function testLinkWithTiptapAttributes(): void
	{
		// Real Tiptap link output includes target/rel/nofollow — verifies the
		// library handles the extra attrs gracefully (markdown link syntax has
		// no slot for them, so they're dropped, which is correct).
		$html   = '<p>See <a target="_blank" rel="noopener noreferrer nofollow" href="https://example.com">our docs</a>.</p>';
		$result = trim($this->converter->convert($html));

		$this->assertSame('See [our docs](https://example.com).', $result);
	}

	public function testImageWithAltText(): void
	{
		$html   = '<p><img src="https://example.com/cat.jpg" alt="A cat"></p>';
		$result = trim($this->converter->convert($html));

		$this->assertSame('![A cat](https://example.com/cat.jpg)', $result);
	}

	// ─── Blocks: code, blockquote, hr ────────────────────────────────────────

	public function testCodeBlock(): void
	{
		$html   = '<pre><code>$cms->collection("blog");</code></pre>';
		$result = trim($this->converter->convert($html));

		// Either fenced ``` or indented 4-space — both are valid markdown.
		$this->assertStringContainsString('$cms->collection("blog");', $result);
		$this->assertMatchesRegularExpression('/```|    /', $result);
	}

	public function testBlockquote(): void
	{
		$html   = '<blockquote><p>Quoted text here.</p></blockquote>';
		$result = trim($this->converter->convert($html));

		$this->assertStringContainsString('> Quoted text here.', $result);
	}

	public function testHorizontalRule(): void
	{
		$result = trim($this->converter->convert('<p>Before</p><hr><p>After</p>'));
		$this->assertMatchesRegularExpression('/Before.*[-*_]{3,}.*After/s', $result);
	}

	// ─── Real-world dev-data sample (factory-generated lorem with mixed marks) ──

	public function testRealStyledtextFromDevDataRoundTrips(): void
	{
		// Pinned from a real blog post on the dev install — exercises the
		// common case (paragraph + strong + em + link with Tiptap attrs).
		$html = '<p>Voluptas placeat quia praesentium et magni illo accusamus voluptas ullam. ' .
			'Alias excepturi dolores quis <a target="_blank" rel="noopener noreferrer nofollow" href="#sed">hic voluptas</a> ' .
			'enim soluta facere fuga officia. Neque <strong>quo aliquid</strong> consequatur et ' .
			'enim aut <em>repellendus vel</em> in.</p>';

		$result = trim($this->converter->convert($html));

		$this->assertStringContainsString('[hic voluptas](#sed)', $result);
		$this->assertStringContainsString('**quo aliquid**', $result);
		$this->assertStringContainsString('*repellendus vel*', $result);
		// No HTML tags remain in the output.
		$this->assertDoesNotMatchRegularExpression('/<[^>]+>/', $result);
	}

	// ─── Edge: unknown tags ──────────────────────────────────────────────────

	public function testUnknownTagsArePreservedAsHtmlWithoutCrash(): void
	{
		// T3 Tiptap extensions may emit custom elements (FileLink, VideoEmbed,
		// etc.) that the library doesn't recognize. We don't want crashes —
		// pass through gracefully. Future enhancement: register custom
		// converters for the T3-specific elements.
		$html   = '<p>Before <custom-tag>middle</custom-tag> after.</p>';
		$result = $this->converter->convert($html);

		// Either preserves the tag or strips it — both are acceptable. What's
		// NOT acceptable is throwing or losing the "middle" content.
		$this->assertStringContainsString('Before', $result);
		$this->assertStringContainsString('middle', $result);
		$this->assertStringContainsString('after', $result);
	}
}
