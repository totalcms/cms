<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Service;

use TotalCMS\Domain\Rendering\Utilities\TemplatePlaceholder;
use TotalCMS\Domain\Seo\Data\MetaPayload;
use TotalCMS\Domain\Seo\Data\SeoContext;
use TotalCMS\Domain\Seo\Data\SeoSettings;

/**
 * Resolves the `<head>` meta values for a SeoContext.
 *
 * Pure by design — no dependencies, no I/O. Everything it needs (image URLs
 * included) has already been resolved onto the context by SeoContextFactory,
 * which keeps the fallback chain trivially testable.
 */
class MetaBuilder
{
	/** Maximum description length before truncation, in characters. */
	private const DESCRIPTION_LENGTH = 160;

	/**
	 * Arms the markdown strip. Deliberately narrow: it looks for markdown
	 * *shapes*, never a bare punctuation mark, because a description that is
	 * plain prose must come back byte-identical. A lone `!` or `[` is not
	 * evidence of markdown — "Wow! 100%" and "Price: $5 * 3" are sentences.
	 *
	 * Alternatives, in order: an image, a link, inline code, a strong wrapper,
	 * an emphasis opener at a word boundary (so `2 * 2` and `my_var` are out),
	 * and the four block markers at the start of a line.
	 */
	private const MARKDOWN_MARKERS = '/!\[|\[[^\]]+\]\(|`|\*\*|__|(?<!\w)[*_](?=\S)|^\s{0,3}(?:#{1,6}\s|>\s?|[-+*]\s|\d+\.\s)/m';

	public function build(SeoContext $ctx): MetaPayload
	{
		$f = $ctx->fields;
		$s = $ctx->settings;

		// Title: the seo card wins, then the collection's mapped title property,
		// then the object's own title, then the site.
		$rawTitle = $this->cardTitle($ctx);
		if ($rawTitle === '' && $ctx->seoBlock['title'] !== '') {
			$rawTitle = $this->scalarString($ctx->object[$ctx->seoBlock['title']] ?? null);
		}
		if ($rawTitle === '') {
			$rawTitle = $this->scalarString($ctx->object['title'] ?? null);
		}
		$title = $this->applyTemplate($rawTitle, $ctx->siteName, $s);

		// Description: the seo card, then the collection's mapped property
		// (stripped to plain text), then the site default.
		$description = $f->description;
		if ($description === '' && $ctx->seoBlock['description'] !== '') {
			$description = $this->plainText($this->scalarString($ctx->object[$ctx->seoBlock['description']] ?? null));
		}
		if ($description === '') {
			$description = $s->defaultDescription;
		}
		$description = $this->truncate($description, self::DESCRIPTION_LENGTH);

		// Image: the seo card's own image, then the collection's mapped image
		// property, then the site default. Both card and mapped URLs were
		// resolved by the factory. The alt is read in the same branches, so it
		// always describes the image that actually won rather than a runner-up.
		$image    = '';
		$imageAlt = '';
		if ($f->hasImage()) {
			$image    = $ctx->imageUrls['seo.image'] ?? '';
			$imageAlt = $ctx->imageAlts['seo.image'] ?? '';
		}
		if ($image === '' && $ctx->seoBlock['image'] !== '') {
			$image    = $ctx->imageUrls[$ctx->seoBlock['image']] ?? '';
			$imageAlt = $ctx->imageAlts[$ctx->seoBlock['image']] ?? '';
		}
		if ($image === '') {
			$image    = $s->defaultImage;
			$imageAlt = $s->defaultImageAlt;
		}
		// No image at all: an alt describing nothing is worse than no alt.
		if ($image === '') {
			$imageAlt = '';
		}

		$canonical = $f->canonical !== '' ? $f->canonical : $ctx->url;
		$robots    = implode(', ', array_filter([$f->noindex ? 'noindex' : '', $f->nofollow ? 'nofollow' : '']));
		$ogType    = $ctx->seoBlock['type'] === 'article' ? 'article' : 'website';

		return new MetaPayload(
			title: $title,
			rawTitle: $rawTitle,
			socialTitle: $f->socialTitle,
			description: $description,
			canonical: $canonical,
			robots: $robots,
			ogType: $ogType,
			ogImage: $image,
			ogImageAlt: $imageAlt,
			twitterCard: $image !== '' ? 'summary_large_image' : 'summary',
			siteName: $ctx->siteName,
			twitterHandle: $s->twitterHandle,
			verification: $s->verification,
			noindex: $f->noindex,
		);
	}

	/**
	 * The `seo` card's own title, with `${placeholder}` support so one card
	 * value can compose a title out of the record ("${name} — ${city}").
	 *
	 * Keys resolve against the record with the same dot-path walk decks and
	 * autogen use, plus `${site}` for the site name — `site` is claimed by the
	 * site name even when the record has a property of that name.
	 *
	 * A template whose placeholders ALL come back empty returns `''` and falls
	 * through to the rest of the chain rather than emitting a half-built title.
	 * The literal text around the placeholders does not keep it alive: with
	 * neither property, `${name} — ${city}` would otherwise trim to a bare `—`
	 * and become the page title. One placeholder resolving is enough — that is
	 * a real, if partial, title (`${name} — ${city}` with only a name renders
	 * `Bistro —`, trimmed of trailing whitespace).
	 */
	private function cardTitle(SeoContext $ctx): string
	{
		$title = $ctx->fields->title;

		// No *complete* placeholder: a literal `${` with no closing brace is
		// text, not a template, and must survive as the title it was typed as.
		if (TemplatePlaceholder::extractKeys($title) === []) {
			return $title;
		}

		$resolved = false;
		$rendered = TemplatePlaceholder::render($title, function (string $key) use ($ctx, &$resolved): string {
			$value = $key === 'site' ? $ctx->siteName : TemplatePlaceholder::resolvePath($ctx->object, $key);
			if ($value !== '') {
				$resolved = true;
			}

			return $value;
		});

		return $resolved ? trim($rendered) : '';
	}

	/**
	 * Render the site title template. A missing title or site name collapses
	 * to whichever half exists rather than leaving a dangling separator.
	 */
	private function applyTemplate(string $title, string $site, SeoSettings $s): string
	{
		if ($title === '') {
			return $site;
		}
		if ($site === '') {
			return $title;
		}

		// The separator is configured independently of the template, so the
		// literal `|` in the default template is the substitution point.
		$template = str_replace('|', $s->titleSeparator, $s->titleTemplate);

		return trim(str_replace(['{title}', '{site}'], [$title, $site], $template));
	}

	/**
	 * A mapped property can point at anything the schema declares — an image,
	 * a card, a deck. Only a scalar is meaningful as text; everything else
	 * reads as empty so the fallback chain continues to the site default
	 * rather than emitting the literal string "Array".
	 */
	private function scalarString(mixed $value): string
	{
		return is_scalar($value) ? trim((string)$value) : '';
	}

	/** Flatten markup, markdown and entities to a single line of plain text. */
	private function plainText(string $html): string
	{
		$text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

		// A mapped property is as often markdown as HTML, and strip_tags leaves
		// markdown untouched — a description reading `**Bold** and [a](url)`
		// helps nobody. Only pay for the strip when a marker actually survived.
		if (preg_match(self::MARKDOWN_MARKERS, $text) === 1) {
			$text = $this->stripMarkdown($text);
		}

		return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
	}

	/**
	 * Drop the common inline and block markdown markers, keeping the text they
	 * wrap. A regex pass by design: this builds one meta tag, and a full parser
	 * would buy correctness on constructs a 160-character description will
	 * never contain.
	 *
	 * Order matters — images before links (an image is a link with a bang),
	 * code before emphasis (so `*` inside a code span is already gone), strong
	 * before emphasis (so `**` is not eaten one asterisk at a time).
	 *
	 * The emphasis wrappers require a non-space immediately inside them, and
	 * the underscore forms a word boundary outside them, so `2 * 2` keeps its
	 * asterisks and `my_var` keeps its underscore. A real `__x__` wrapper does
	 * strip — `__init__` reads as strong markdown and nothing in a description
	 * tells the two apart.
	 */
	private function stripMarkdown(string $text): string
	{
		$replacements = [
			'/!\[([^\]]*)\]\([^)]*\)/'             => '$1', // ![alt](url)
			'/\[([^\]]+)\]\([^)]*\)/'              => '$1', // [text](url)
			'/`([^`]*)`/'                          => '$1', // `code`
			'/\*\*(?!\s)(.+?)(?<!\s)\*\*/'         => '$1', // **strong**
			'/(?<!\w)__(?!\s)(.+?)(?<!\s)__(?!\w)/' => '$1', // __strong__
			'/\*(?!\s)(.+?)(?<!\s)\*/'             => '$1', // *emphasis*
			'/(?<!\w)_(?!\s)(.+?)(?<!\s)_(?!\w)/'  => '$1', // _emphasis_
			'/^\s{0,3}#{1,6}\s+/m'                 => '',   // # heading
			'/^\s*(?:[-+*]|\d+\.)\s+/m'            => '',   // - item, 1. item
			'/^\s*>\s?/m'                          => '',   // > blockquote
		];

		// A catastrophic backtrack or a bad subject returns null; the original
		// text is a far better description than the empty string a cast gives.
		return preg_replace(array_keys($replacements), array_values($replacements), $text) ?? $text;
	}

	/**
	 * Truncate on a word boundary, leaving room for the ellipsis so the result
	 * never exceeds $max characters.
	 */
	private function truncate(string $text, int $max): string
	{
		if (mb_strlen($text) <= $max) {
			return $text;
		}
		$cut = mb_substr($text, 0, $max - 1);
		$sp  = mb_strrpos($cut, ' ');

		return rtrim($sp === false ? $cut : mb_substr($cut, 0, $sp), ' ,;:') . '…';
	}
}
