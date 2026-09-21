<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Typography\Service;

use TotalCMS\Domain\Typography\Data\QuoteStyle;
use TotalCMS\Domain\Typography\Data\TypographyOptions;

/**
 * Replaces the typewriter substitutes people type with the glyphs a
 * typesetter uses — curly quotes, real dashes, an ellipsis, × and ±, no-break
 * spaces where a line break is a mistake — in HTML or plain text.
 *
 * The content is split into tags and text runs; the rules only ever see the
 * text runs, and never the ones inside code, pre, script and the other raw
 * elements. Three pieces of state carry across runs so `<em>"quoted"</em>`
 * opens correctly and `He said "I was 5"` ends with a quote rather than an
 * inch mark: the previous visible character, and whether a double or single
 * quote is currently open. Both reset at block boundaries.
 *
 * Idempotent: the rules match only the ASCII substitutes, so output — and
 * anything already typed properly — passes through untouched.
 */
final class Typographer
{
	private const RAW_ELEMENTS = ['code', 'pre', 'kbd', 'samp', 'var', 'script', 'style', 'textarea', 'svg', 'math'];

	private const BLOCK_ELEMENTS = [
		'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'dt', 'dd', 'figcaption', 'blockquote', 'div', 'section',
		'article', 'header', 'footer', 'main', 'nav', 'aside', 'td', 'th', 'tr', 'table', 'thead', 'tbody', 'tfoot',
		'ul', 'ol', 'pre', 'hr', 'figure', 'details', 'summary', 'address', 'form', 'fieldset',
	];

	/** Blocks whose last two words are joined so no lone word ends up on the last line. */
	private const WIDONT_ELEMENTS = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'dt', 'dd', 'figcaption'];

	/** A tag starts with a letter, a slash or `!` — so `a <- b` in prose is not a tag. */
	private const TAG_PATTERN = '/(<!--.*?-->|<\/?[a-zA-Z!][^>]*>)/s';

	private const APOSTROPHE   = '’';
	private const PRIME        = '′';
	private const DOUBLE_PRIME = '″';

	/** Units and symbols a number must not be separated from. Deliberately excludes words that are also English (`in`, `s`, `m`, `h`). */
	private const UNITS = 'kg|mg|km|cm|mm|ft|lb|lbs|oz|min|ms|am|pm|px|pt|MB|GB|KB|TB|Hz|kHz|MHz|GHz|kW|mAh|mph|°C|°F|°|%';

	private const ABBREVIATIONS = 'Mr|Mrs|Ms|Dr|Prof|No|St|vs|pp|p|Fig|ca|approx';

	/** @var array{prev:string, double:bool, single:bool} */
	private array $state = ['prev' => '', 'double' => false, 'single' => false];

	/**
	 * Text runs of the current block still to be wrapped (§wrap), keyed by
	 * token index: the tag before the run and whether the run opens the block.
	 * Wrapping happens after widont so its spans are never mistaken for text.
	 *
	 * @var array<int,array{0:?string,1:bool}>
	 */
	private array $pendingWrap = [];

	public function process(string $content, TypographyOptions $options): string
	{
		if ($content === '') {
			return '';
		}

		$this->resetState();

		// No tags: one text run, treated as one block. The split is the only
		// thing this path skips; the rules cost the same either way.
		if (!str_contains($content, '<')) {
			$out = [$this->processText($content, $options, null, 0)];
			$this->finishBlock($out, [0], $options);

			return $out[0];
		}

		$tokens = preg_split(self::TAG_PATTERN, $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
		if ($tokens === false) {
			return $content;
		}

		$out         = [];
		$rawDepth    = 0;
		$blockTokens = [];

		foreach ($tokens as $i => $token) {
			if ($token[0] === '<') {
				$out[$i] = $token;
				if (str_starts_with($token, '<!--') || preg_match('/^<(\/?)([a-zA-Z][a-zA-Z0-9-]*)/', $token, $m) !== 1) {
					continue;
				}
				$closing = $m[1] === '/';
				$name    = strtolower($m[2]);

				if (in_array($name, self::RAW_ELEMENTS, true)) {
					if ($closing) {
						$rawDepth = max(0, $rawDepth - 1);
					} elseif (!str_ends_with($token, '/>')) {
						$rawDepth++;
					}
				}

				if ($name === 'br') {
					$this->state['prev'] = '';
					continue;
				}

				if (in_array($name, self::BLOCK_ELEMENTS, true)) {
					$this->finishBlock($out, $blockTokens, $options, $closing && in_array($name, self::WIDONT_ELEMENTS, true));
					$this->resetState();
					$blockTokens = [];
					// An inline raw element left open (`<p><code>…</p>`) ends with its block.
					if ($closing) {
						$rawDepth = 0;
					}
				}
				continue;
			}

			if ($rawDepth > 0) {
				$out[$i] = $token;
				continue;
			}

			$out[$i]       = $this->processText($token, $options, $i > 0 ? $tokens[$i - 1] : null, $i);
			$blockTokens[] = $i;
		}

		// A fragment with no closing block tag still gets its last line kept whole.
		$this->finishBlock($out, $blockTokens, $options);

		return implode('', $out);
	}

	private function resetState(): void
	{
		$this->state       = ['prev' => '', 'double' => false, 'single' => false];
		$this->pendingWrap = [];
	}

	/**
	 * The block-level passes: widont over the block's runs, then the wrap
	 * spans. Widont must come first — it looks for the last whitespace in
	 * the block's text and would otherwise land inside a span tag.
	 *
	 * @param array<int,string> $out
	 * @param array<int>        $blockTokens
	 */
	private function finishBlock(array &$out, array $blockTokens, TypographyOptions $o, bool $widont = true): void
	{
		if ($o->widont && $widont) {
			$this->widont($out, $blockTokens);
		}
		if ($o->wrap) {
			foreach ($this->pendingWrap as $i => [$previousTag, $atBlockStart]) {
				$out[$i] = $this->wrap($out[$i], $previousTag, $atBlockStart);
			}
		}
		$this->pendingWrap = [];
	}

	private function processText(string $text, TypographyOptions $o, ?string $previousTag, int $index): string
	{
		if ($o->wrap) {
			$this->pendingWrap[$index] = [$previousTag, $this->state['prev'] === ''];
		}

		$s = str_replace(['&quot;', '&#34;', '&#x22;', '&#X22;'], '"', $text);
		$s = str_replace(['&#39;', '&#x27;', '&#X27;', '&apos;'], "'", $s);

		if ($o->ellipsis) {
			$s = str_replace('...', '…', $s);
		}
		if ($o->math) {
			$s = $this->math($s);
		}
		if ($o->dashes !== false) {
			$s = $this->dashes($s, $o->dashes);
		}
		if ($o->symbols) {
			$s = (string)preg_replace(['/\(c\)/i', '/\(r\)/i', '/\(tm\)/i'], ['©', '®', '™'], $s);
		}
		if ($o->quotes !== false || $o->primes) {
			$s = $this->quotes($s, $o);
		}
		if ($o->nbsp) {
			$s = $this->nbsp($s, $o);
		}
		if ($o->fractions) {
			$s = (string)preg_replace_callback(
				'#(?<![\d/])(1/2|1/4|3/4|1/3|2/3|1/8|3/8|5/8|7/8)(?![\d/])#',
				static fn (array $m): string => ['1/2' => '½', '1/4' => '¼', '3/4' => '¾', '1/3' => '⅓', '2/3' => '⅔', '1/8' => '⅛', '3/8' => '⅜', '5/8' => '⅝', '7/8' => '⅞'][$m[1]],
				$s
			);
		}
		if ($o->ordinals) {
			$s = (string)preg_replace('/\b(\d+)(st|nd|rd|th)\b/', '$1<sup>$2</sup>', $s);
		}

		$this->state['prev'] = $this->lastVisibleChar($s);

		return $s;
	}

	private function math(string $s): string
	{
		// Arrows first: they contain the hyphen the dash rules look for. `<`
		// and `>` arrive as entities in HTML text and literally in plain text.
		$s = str_replace(['->', '-&gt;'], '→', $s);
		$s = str_replace(['<-', '&lt;-'], '←', $s);
		$s = str_replace(['<=', '&lt;='], '≤', $s);
		$s = str_replace(['>=', '&gt;='], '≥', $s);
		$s = str_replace('!=', '≠', $s);
		$s = str_replace(['+/-', '+-'], '±', $s);

		// digit x digit, with or without single spaces — never `0x1F` and never next to a letter.
		return (string)preg_replace('/(?<=\d)(?<!\b0)( ?)x( ?)(?=\d)/', '$1×$2', $s);
	}

	private function dashes(string $s, string $mode): string
	{
		// A spaced hyphen (or double hyphen) between words: an unspaced em dash
		// in US style, a spaced en dash in UK style. The regex consumes the
		// spaces so a hand-typed " — " is never touched.
		if ($mode === TypographyOptions::DASHES_EM) {
			$s = (string)preg_replace('/(?<=\S) -{1,3} (?=\S)/', '—', $s);
			$s = (string)preg_replace('/-{2,3}/', '—', $s);
		} else {
			$s = (string)preg_replace('/(?<=\S) -{1,3} (?=\S)/', ' – ', $s);
			$s = str_replace('---', '—', $s);
			$s = str_replace('--', '–', $s);
		}

		// Digit ranges: 1990-2000, pp. 12-15, 9-5. Not ISO dates or part numbers
		// (more than one hyphen in the token) and not decimals or paths.
		$s = (string)preg_replace('#(?<![\w\-/.])(\d+)-(\d+)(?![\w\-/.])#', '$1–$2', $s);

		// A minus: a hyphen after whitespace or an opening bracket, before a digit.
		return (string)preg_replace('/(?<=^|[\s(\[])-(?=\d)/', '−', $s);
	}

	private function quotes(string $s, TypographyOptions $o): string
	{
		$style   = $o->quoteStyle;
		$pad     = $style->spaced ? QuoteStyle::NARROW_NBSP : '';
		$doQuote = $o->quotes !== false;

		$result = preg_replace_callback(
			'/["\']/',
			function (array $m) use ($s, $style, $pad, $doQuote, $o): string {
				$hit    = $m[0];
				$offset = $hit[1];
				$char   = $hit[0];
				$prev   = $offset > 0 ? $this->lastVisibleChar(substr($s, 0, $offset)) : $this->state['prev'];
				$rest   = substr($s, $offset + 1);
				$next   = $this->firstVisibleChar($rest);

				$prevIsDigit  = $prev !== '' && ctype_digit($prev);
				$prevIsLetter = preg_match('/^\p{L}$/u', $prev) === 1;
				$nextIsLetter = preg_match('/^\p{L}$/u', $next) === 1;
				$opening      = $this->isOpeningContext($prev);

				if ($char === '"') {
					if ($this->state['double']) {
						$this->state['double'] = false;

						return $doQuote ? $pad . $style->closeDouble : '"';
					}
					if ($o->primes && $prevIsDigit) {
						return self::DOUBLE_PRIME;
					}
					if (!$doQuote) {
						return '"';
					}
					if ($opening) {
						$this->state['double'] = true;

						return $style->openDouble . $pad;
					}

					return $pad . $style->closeDouble;
				}

				// Apostrophes come before quotes: they are the common case.
				if ($prevIsLetter && $nextIsLetter) {
					return self::APOSTROPHE;
				}
				if ($o->primes && $prevIsDigit && !$this->state['single']) {
					return self::PRIME;
				}
				if (!$prevIsLetter && ($next !== '' && ctype_digit($next) || preg_match("/^(?:tis|twas|til|cause|em)\\b|^n'/i", $rest) === 1)) {
					return self::APOSTROPHE;
				}
				if (!$doQuote) {
					return "'";
				}
				if ($this->state['single']) {
					$this->state['single'] = false;

					return $style->closeSingle;
				}
				if ($opening) {
					$this->state['single'] = true;

					return $style->openSingle;
				}

				return self::APOSTROPHE;
			},
			$s,
			-1,
			$count,
			PREG_OFFSET_CAPTURE
		);

		return $result ?? $s;
	}

	private function isOpeningContext(string $prev): bool
	{
		return $prev === ''
			|| preg_match('/^[\s(\[{“‘„‚«‹—–\-\/]$/u', $prev) === 1;
	}

	private function nbsp(string $s, TypographyOptions $o): string
	{
		$s = (string)preg_replace('/(\d) (' . self::UNITS . ')(?![\p{L}\d])/u', '$1&nbsp;$2', $s);
		$s = (string)preg_replace('/\b(' . self::ABBREVIATIONS . ')\. (?=\S)/', '$1.&nbsp;', $s);
		$s = (string)preg_replace('/([§№]) (?=\d)/u', '$1&nbsp;', $s);

		if ($o->quoteStyle->spaced) {
			// French: a narrow no-break space before ? ! ; and before a colon
			// that ends a clause. Entities are matched first so their `;` is
			// never mistaken for punctuation.
			$s = (string)preg_replace_callback(
				'/&[#\w]+;| ?[?!;]| ?:(?=\s|$)/',
				function (array $m) use ($s): string {
					[$match, $offset] = $m[0];
					if ($match[0] === '&' || $offset === 0 || str_ends_with(substr($s, 0, $offset), '&#8239;')) {
						return $match;
					}

					return '&#8239;' . substr($match, -1);
				},
				$s,
				-1,
				$count,
				PREG_OFFSET_CAPTURE
			);
		}

		return $s;
	}

	/**
	 * Join the last two words of a block with a no-break space. Works over the
	 * block's text runs so a trailing inline tag (`the <a>end</a>.`) still
	 * gets the space before it. Skips blocks under three words and blocks
	 * whose last word is already joined.
	 *
	 * @param array<int,string> $out
	 * @param array<int>        $blockTokens
	 */
	private function widont(array &$out, array $blockTokens): void
	{
		if ($blockTokens === []) {
			return;
		}

		$text  = implode('', array_map(static fn (int $i): string => $out[$i], $blockTokens));
		$words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
		if ($words === false || count($words) < 3) {
			return;
		}
		$last = end($words);
		if (str_contains($last, '&nbsp;') || str_contains($last, "\u{00A0}") || str_contains($last, '&#160;')) {
			return;
		}

		$seenWord = false;
		foreach (array_reverse($blockTokens) as $i) {
			$run = $out[$i];
			if ($seenWord && preg_match('/\s+$/u', $run) === 1) {
				$out[$i] = (string)preg_replace('/\s+$/u', '&nbsp;', $run);

				return;
			}
			if (preg_match('/^(.*\S)\s+(\S[^\s]*\s*)$/su', $run, $m) === 1) {
				$out[$i] = $m[1] . '&nbsp;' . $m[2];

				return;
			}
			if (preg_match('/\S/u', $run) === 1) {
				$seenWord = true;
			}
		}
	}

	private function wrap(string $s, ?string $previousTag, bool $atBlockStart): string
	{
		$previousTag = $previousTag ?? '';

		if (!str_contains($previousTag, 'class="amp"')) {
			$s = str_replace('&amp;', '<span class="amp">&amp;</span>', $s);
		}
		if (!str_contains($previousTag, 'class="caps"')) {
			$s = (string)preg_replace('/\b([A-Z][A-Z0-9]{2,})\b/', '<span class="caps">$1</span>', $s);
		}
		if ($atBlockStart && !str_contains($previousTag, 'class="dquo"') && !str_contains($previousTag, 'class="squo"')) {
			$s = (string)preg_replace('/^([“„«”])/u', '<span class="dquo">$1</span>', $s);
			$s = (string)preg_replace('/^([‘‚‹’])/u', '<span class="squo">$1</span>', $s);
		}

		return $s;
	}

	/** The last character a reader sees; a no-break space entity counts as a space. */
	private function lastVisibleChar(string $s): string
	{
		if ($s === '') {
			return '';
		}
		if (preg_match('/&(?:nbsp|#160|#8239);$/', $s) === 1) {
			return ' ';
		}

		return mb_substr($s, -1);
	}

	private function firstVisibleChar(string $s): string
	{
		if ($s === '') {
			return '';
		}
		if (preg_match('/^&(?:nbsp|#160|#8239);/', $s) === 1) {
			return ' ';
		}

		return mb_substr($s, 0, 1);
	}
}
