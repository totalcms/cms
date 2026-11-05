<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\ClientHelp;

use TotalCMS\Domain\Docs\Service\DocsMarkdownRenderer;
use TotalCMS\Domain\Property\Data\MarkdownData;
use TotalCMS\Domain\Rendering\Utilities\EmbedBuilder;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Security\Sanitization\HTMLSanitizer;
use TotalCMS\Domain\Twig\Adapter\CollectionTwigAdapter;
use TotalCMS\Domain\Twig\Markdown\ParsedownMarkdown;
use TotalCMS\Domain\Video\Service\VideoUrlResolver;

/**
 * The help collection as a reader sees it. Core read paths return drafts, so
 * this class is the one place that keeps them from clients.
 *
 * `editor` can write to the collection by default and the admin has no script
 * restrictions, so a post's body never reaches the page unsanitized.
 */
readonly class HelpLibrary
{
	public const GENERAL = 'General';

	private const EXTRA_SPLIT = '<!--client-help-extra-->';

	public function __construct(
		private CollectionTwigAdapter $collections,
		private SchemaFetcher $schemas,
		private DocsMarkdownRenderer $headings,
		private VideoUrlResolver $videos,
	) {
	}

	/** @return list<array<string,mixed>> */
	public function published(): array
	{
		$posts = array_filter(
			$this->collections->objects(Extension::COLLECTION),
			fn (array $post): bool => !$this->isOn($post['draft'] ?? false),
		);

		return $this->sorted(array_values($posts));
	}

	/** @return list<array{title: string, posts: list<array<string,mixed>>}> */
	public function groups(): array
	{
		$groups = [];
		foreach ($this->published() as $post) {
			foreach ($this->categories($post) as $category) {
				$groups[$category][] = $post;
			}
		}

		$titles = array_map('strval', array_keys($groups));
		usort($titles, $this->compare(...));

		return array_map(
			static fn (string $title): array => ['title' => $title, 'posts' => $groups[$title]],
			$titles,
		);
	}

	/** @return list<array<string,mixed>> */
	public function featured(): array
	{
		$posts = array_filter(
			$this->published(),
			fn (array $post): bool => $this->isOn($post['featured'] ?? false),
		);

		return array_map(fn (array $post): array => $post + ['excerpt' => $this->excerpt($post)], array_values($posts));
	}

	/** A date field can hold any string, and Twig's date filter would throw on a bad one. */
	public static function readableDate(mixed $value): string
	{
		if (!is_string($value) || trim($value) === '') {
			return '';
		}

		try {
			return (new \DateTimeImmutable($value))->format('F j, Y');
		} catch (\Exception) {
			return '';
		}
	}

	/**
	 * Null for a missing post and for a draft alike, so a draft's URL reveals
	 * nothing.
	 *
	 * @return array{post: array<string,mixed>, html: string, extra: string, toc: list<array{level:int,id:string,text:string}>, category: string, summary: string, date: string, updated: string, tags: list<string>, media: string, mediaUrl: string}|null
	 */
	public function article(string $id): ?array
	{
		$post = $this->collections->object(Extension::COLLECTION, $id);
		if ($post === [] || $this->isOn($post['draft'] ?? false)) {
			return null;
		}

		// Anchored as one document so a heading repeated in `extra` gets its own id.
		[$html, $toc]   = $this->headings->withHeadingAnchors(
			$this->body((string)($post['content'] ?? '')) . self::EXTRA_SPLIT . $this->body((string)($post['extra'] ?? '')),
		);
		[$body, $extra] = explode(self::EXTRA_SPLIT, $html, 2);
		$mediaUrl       = $this->webUrl($post['media'] ?? '');

		return [
			'post'     => $post,
			'html'     => $body,
			'extra'    => $extra,
			'toc'      => $toc,
			'category' => $this->categories($post)[0],
			'summary'  => $this->body((string)($post['summary'] ?? '')),
			'date'     => self::readableDate($post['date'] ?? ''),
			'updated'  => self::readableDate($post['updated'] ?? ''),
			'tags'     => $this->listValues($post['tags'] ?? []),
			'media'    => $this->embed($mediaUrl),
			'mediaUrl' => $mediaUrl,
		];
	}

	private function body(string $content): string
	{
		if ($content === '') {
			return '';
		}

		return $this->isMarkdown()
			? (new ParsedownMarkdown())->convert($content)
			: HTMLSanitizer::sanitizeRichContent($content);
	}

	/** @param array<string,mixed> $post */
	private function excerpt(array $post): string
	{
		$text = strip_tags($this->body((string)($post['summary'] ?? '')));

		return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5));
	}

	/** Only absolute web addresses: an editor sets this, and it lands in an admin's page. */
	private function webUrl(mixed $url): string
	{
		if (!is_string($url)) {
			return '';
		}

		$url = trim($url);

		return preg_match('~^https?://[^\s/]~i', $url) === 1 ? $url : '';
	}

	/** Embeds what a player can show. Any other site gets a link, since most refuse to load in a frame. */
	private function embed(string $url): string
	{
		if ($url === '' || !$this->isPlayable($url)) {
			return '';
		}

		return EmbedBuilder::embed($url, ['responsive' => true]);
	}

	private function isPlayable(string $url): bool
	{
		return str_ends_with(strtolower((string)parse_url($url, PHP_URL_PATH)), '.mp3')
			|| $this->videos->resolve($url)->provider !== 'unknown';
	}

	/** Decided by field type, never by sniffing the text. A freelancer can move the collection to `blog`. */
	private function isMarkdown(): bool
	{
		$field = $this->schemas->fetchSchemaForCollection(Extension::COLLECTION)->properties['content']['field'] ?? '';

		return in_array($field, MarkdownData::FIELDS, true);
	}

	/**
	 * @param array<string,mixed> $post
	 *
	 * @return non-empty-list<string>
	 */
	private function categories(array $post): array
	{
		$categories = $this->listValues($post['categories'] ?? []);

		return $categories === [] ? [self::GENERAL] : $categories;
	}

	/** @return list<string> */
	private function listValues(mixed $values): array
	{
		$values = array_map(
			static fn (mixed $value): string => is_scalar($value) ? trim((string)$value) : '',
			(array)$values,
		);

		return array_values(array_unique(array_filter($values, static fn (string $value): bool => $value !== '')));
	}

	/**
	 * @param list<array<string,mixed>> $posts
	 *
	 * @return list<array<string,mixed>>
	 */
	private function sorted(array $posts): array
	{
		usort($posts, fn (array $a, array $b): int => $this->compare((string)($a['title'] ?? ''), (string)($b['title'] ?? '')));

		return $posts;
	}

	/** Plain byte compare of folded text: strnatcasecmp folds by locale. */
	private function compare(string $a, string $b): int
	{
		return strcmp(mb_strtolower($a), mb_strtolower($b));
	}

	private function isOn(mixed $value): bool
	{
		return filter_var($value, FILTER_VALIDATE_BOOLEAN);
	}
}
