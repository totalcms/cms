<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Docs\Service;

use TotalCMS\Domain\Property\Data\SlugData;
use Webuni\FrontMatter\FrontMatterChain;

/**
 * Renders a documentation page: front matter, ParsedownExtra (unsafe mode —
 * docs embed their own HTML), the GFM table-pipe fix-up, and heading anchors
 * with a flat table of contents.
 */
class DocsMarkdownRenderer
{
	/**
	 * `title` is the page's first H1, falling back to the front matter's
	 * `title` — the name the search index and quick-nav show for it.
	 *
	 * @return array{data: array<string,mixed>, content: string, toc: list<array{level:int,id:string,text:string}>, title: string}
	 */
	public function render(string $markdown): array
	{
		$document = FrontMatterChain::create()->parse($markdown);

		$html         = (new \ParsedownExtra())->text($document->getContent());
		$html         = $this->unescapeTablePipes($html);
		[$html, $toc] = $this->injectHeadingAnchors($html);

		/** @var array<string,mixed> $data */
		$data = $document->getData();

		$title = preg_match('/<h1\b[^>]*>(.*?)<\/h1>/is', $html, $h1)
			? trim(html_entity_decode(strip_tags($h1[1]), ENT_QUOTES | ENT_HTML5))
			: trim((string)($data['title'] ?? ''));

		return ['data' => $data, 'content' => $html, 'toc' => $toc, 'title' => $title];
	}

	/**
	 * The rendered page as the flat text a search index wants: tags gone,
	 * code kept, entities decoded, whitespace collapsed.
	 */
	public static function searchText(string $html): string
	{
		$text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);

		return trim((string)preg_replace('/\s+/', ' ', $text));
	}

	/**
	 * Unescape `\|` to `|` inside table cells.
	 *
	 * In a GFM table, a pipe inside a code span must be written `\|` so the
	 * table parser doesn't treat it as a column delimiter — and GFM (and Astro
	 * on docs.totalcms.co) strip that escaping backslash before inline parsing,
	 * so `` `\| markdown` `` renders as `| markdown`. Parsedown 1.8 recognizes
	 * `\|` as a non-delimiter but leaves the backslash in the code span, so the
	 * same source showed a literal `\|` in the admin viewer. Within a table cell
	 * an escaped pipe is *always* a literal pipe (a literal backslash-pipe would
	 * be written `\\\|`), so stripping it here is loss-free and matches GFM.
	 */
	private function unescapeTablePipes(string $html): string
	{
		return (string)preg_replace_callback(
			'/<(td|th)\b[^>]*>.*?<\/\1>/is',
			static fn (array $cell): string => str_replace('\\|', '|', $cell[0]),
			$html,
		);
	}

	/**
	 * Inject id attributes on h2/h3 elements and return a flat TOC array.
	 * Skips headings that already carry an id (ParsedownExtra `{#custom-id}`).
	 *
	 * @return array{0:string,1:list<array{level:int,id:string,text:string}>}
	 */
	private function injectHeadingAnchors(string $html): array
	{
		$toc     = [];
		$usedIds = [];
		$pattern = '/<h([23])(\s[^>]*)?>(.*?)<\/h\1>/i';

		$replaced = preg_replace_callback($pattern, function (array $m) use (&$toc, &$usedIds): string {
			$level = (int)$m[1];
			$attrs = $m[2];
			$inner = $m[3];
			$text  = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5));

			if (preg_match('/\bid\s*=\s*["\']([^"\']+)["\']/i', $attrs, $idMatch)) {
				$id = $idMatch[1];
			} else {
				$id = SlugData::slugify($text);
				if ($id === '') {
					return $m[0];
				}
				$base = $id;
				$n    = 2;
				while (in_array($id, $usedIds, true)) {
					$id = $base . '-' . $n++;
				}
				$attrs = ' id="' . htmlspecialchars($id, ENT_QUOTES) . '"' . $attrs;
			}

			$usedIds[] = $id;
			$toc[]     = ['level' => $level, 'id' => $id, 'text' => $text];

			return '<h' . $level . $attrs . '>' . $inner . '</h' . $level . '>';
		}, $html);

		return [(string)$replaced, $toc];
	}
}
