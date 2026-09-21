<?php

declare(strict_types=1);

use TotalCMS\Domain\Typography\Data\TypographyOptions;
use TotalCMS\Domain\Typography\Service\Typographer;

/**
 * The HTML side of the engine: what it must never touch, and the state it
 * carries across tags so quote direction survives inline markup.
 */
function typographyHtml(string $input, array $options = []): string
{
	return (new Typographer())->process($input, TypographyOptions::fromArray($options + ['widont' => false], 'en_US'));
}

test('attributes are never touched', function (): void {
	$in = '<a href="/x?a=1&amp;b=2" title="don\'t \'quote\' -- me">it\'s "here"</a>';
	expect(typographyHtml($in))->toBe('<a href="/x?a=1&amp;b=2" title="don\'t \'quote\' -- me">it’s “here”</a>');
});

test('code, pre, kbd, samp, script, style, textarea and comments pass through', function (): void {
	$in = '<p>Run <code>echo "hi" -- now</code> then "done"</p>'
		. '<pre><code>x = "a" ... b</code></pre>'
		. '<kbd>Ctrl+"</kbd><samp>1/2 -> 3</samp>'
		. '<script>var s = "no";</script><style>a::before{content:"--"}</style>'
		. '<textarea>type "here"</textarea><!-- "comment" --><p>"after"</p>';

	$out = typographyHtml($in);

	expect($out)->toContain('<code>echo "hi" -- now</code>')
		->and($out)->toContain('then “done”')
		->and($out)->toContain('<pre><code>x = "a" ... b</code></pre>')
		->and($out)->toContain('<kbd>Ctrl+"</kbd><samp>1/2 -> 3</samp>')
		->and($out)->toContain('<script>var s = "no";</script>')
		->and($out)->toContain('content:"--"')
		->and($out)->toContain('<textarea>type "here"</textarea>')
		->and($out)->toContain('<!-- "comment" -->')
		->and($out)->toContain('<p>“after”</p>');
});

test('an unclosed code element keeps the rest of the block raw', function (): void {
	expect(typographyHtml('<p><code>"raw" -- still "raw"</p><p>"prose"</p>'))
		->toBe('<p><code>"raw" -- still "raw"</p><p>“prose”</p>');
});

test('quote direction carries across inline tags', function (): void {
	expect(typographyHtml('<p>He said <em>"quoted"</em> and don<b>\'</b>t</p>'))
		->toBe('<p>He said <em>“quoted”</em> and don<b>’</b>t</p>');
});

test('an opening quote at the start of a block opens, and open state resets at block boundaries', function (): void {
	expect(typographyHtml('<p>"Unclosed</p><p>"Next" one</p>'))
		->toBe('<p>“Unclosed</p><p>“Next” one</p>');
});

test('a quote after a br opens like a new line', function (): void {
	expect(typographyHtml('<p>line one<br>"line two"</p>'))->toBe('<p>line one<br>“line two”</p>');
});

test('entities other than quotes are preserved and the no-break space entity counts as a space', function (): void {
	expect(typographyHtml('<p>Tom &amp; Jerry&nbsp;"quoted" &copy; 2026</p>'))
		->toBe('<p>Tom &amp; Jerry&nbsp;“quoted” &copy; 2026</p>');
});

test('a lone less-than in prose is not a tag', function (): void {
	expect(typographyHtml('a <- b and "x"'))->toBe('a ← b and “x”');
});

test('a self-closing raw element does not swallow the rest of the document', function (): void {
	expect(typographyHtml('<p><svg/>"quoted"</p>'))->toBe('<p><svg/>“quoted”</p>');
});

test('the tiptap fixture matches its golden output', function (): void {
	$in       = (string)file_get_contents(__DIR__ . '/../../fixtures/typography/tiptap.html');
	$expected = (string)file_get_contents(__DIR__ . '/../../fixtures/typography/tiptap.expected.html');
	$out      = (new Typographer())->process($in, TypographyOptions::fromArray([], 'en_US'));

	expect($out)->toBe($expected)
		->and((new Typographer())->process($out, TypographyOptions::fromArray([], 'en_US')))->toBe($expected);
});
