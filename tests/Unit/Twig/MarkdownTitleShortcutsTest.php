<?php

declare(strict_types=1);

use TotalCMS\Domain\Twig\Extension\TotalCMSTwigFilters;
use TotalCMS\Domain\Twig\Markdown\ParsedownMarkdown;

// Two title shortcuts in TotalParsedown. A titled image alone in its paragraph
// renders as a figure captioned by the title; everything else — untitled
// images, images inside a sentence — keeps Parsedown's normal output. A link
// titled "external" or "_blank" opens in a new tab.

describe('markdown figures', function (): void {
	beforeEach(function (): void {
		$this->md = new ParsedownMarkdown();
	});

	it('leaves an image with no title as a plain img in a paragraph', function (): void {
		expect($this->md->convert('![Alt](u.png)'))->toBe('<p><img src="u.png" alt="Alt" /></p>');
	});

	it('captions a titled image with its title and keeps the alt', function (): void {
		$html = $this->md->convert('![Alt](u.png "A different caption")');

		expect($html)->toBe("<figure>\n<img src=\"u.png\" alt=\"Alt\" />\n<figcaption>A different caption</figcaption>\n</figure>");
	});

	it('uses the alt as the caption for a title of caption or alt, and drops the alt attribute', function (string $title): void {
		$html = $this->md->convert("![Alt](u.png \"$title\")");

		expect($html)->toBe("<figure>\n<img src=\"u.png\" />\n<figcaption>Alt</figcaption>\n</figure>");
	})->with(['caption', 'alt', 'Caption', 'ALT']);

	it('leaves the image alone when it asks for its alt as the caption but has no alt', function (): void {
		expect($this->md->convert('![](u.png "caption")'))->not->toContain('<figure>');
	});

	it('does not touch a titled image inside a sentence', function (): void {
		expect($this->md->convert('See ![A](b.png "t") here'))
			->toBe('<p>See <img src="b.png" alt="A" title="t" /> here</p>');
	});

	it('does not touch a paragraph holding two images', function (): void {
		expect($this->md->convert('![A](a.png "x") ![B](b.png "y")'))->not->toContain('<figure>');
	});

	it('does not touch a titled image wrapped in a link', function (): void {
		expect($this->md->convert('[![A](a.png "t")](https://example.test)'))->not->toContain('<figure>');
	});

	it('escapes HTML in the caption', function (): void {
		expect($this->md->convert('![A](a.png "<b>x</b> & y")'))
			->toContain('<figcaption>&lt;b&gt;x&lt;/b&gt; &amp; y</figcaption>');
	});

	it('builds figures through the markdown filter in both HTML modes', function (bool $html): void {
		expect(TotalCMSTwigFilters::markdown('![Alt](u.png "Cap")', $html))->toContain('<figcaption>Cap</figcaption>');
	})->with([false, true]);
});

describe('markdown new-tab links', function (): void {
	beforeEach(function (): void {
		$this->md = new ParsedownMarkdown();
	});

	it('opens a link titled external or _blank in a new tab, with no title', function (string $title): void {
		expect($this->md->convert("[Go](https://example.test \"$title\")"))
			->toBe('<p><a href="https://example.test" target="_blank">Go</a></p>');
	})->with(['external', '_blank', 'External', '_BLANK']);

	it('applies to reference-style links', function (): void {
		expect($this->md->convert("[Go][r]\n\n[r]: https://example.test \"external\""))
			->toContain('<a href="https://example.test" target="_blank">Go</a>');
	});

	it('keeps any other title as a title', function (): void {
		expect($this->md->convert('[Go](https://example.test "Hello")'))
			->toBe('<p><a href="https://example.test" title="Hello">Go</a></p>');
	});

	it('never puts a target on an image', function (): void {
		expect($this->md->convert('See ![A](a.png "external") here'))->not->toContain('target');
	});

	it('works in markdownInline too', function (): void {
		expect(TotalCMSTwigFilters::markdownInline('[Go](https://example.test "external")'))
			->toBe('<a href="https://example.test" target="_blank">Go</a>');
	});
});
