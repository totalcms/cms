<?php

declare(strict_types=1);

namespace TotalCMS\Domain\XmlRpc\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Property\Data\SlugData;
use TotalCMS\Support\Config;

/**
 * Translates between the WordPress post struct and the T3 `blog` schema.
 *
 * The central rule is that `toObject()` returns ONLY keys the client actually
 * sent. WordPress's struct has no concept of `image`, `gallery`, `media` or any
 * custom field, so a mapper that filled in defaults would let a text-only edit
 * from a writing app silently destroy an admin-set hero image. Callers patch
 * with what they get here and nothing else is touched. `draft` is the one
 * field that takes a little care to keep that promise: it is derived, not
 * copied verbatim from a single struct key, so it is only ever set when the
 * struct carries `post_status` or the caller passes an explicit (non-null)
 * publish flag — never invented from a missing flag defaulting to a value.
 */
readonly class PostMapper
{
	/** Upload URL prefixes we rewrite between absolute and path-relative forms. */
	private const UPLOAD_PREFIXES = [
		'imageworks/upload/',
		'download/upload/',
		'stream/upload/',
	];

	public function __construct(
		private Config $config,
		private ObjectUrlBuilder $urlBuilder,
	) {
	}

	/**
	 * @param array<string,mixed> $struct
	 * @param bool|null           $publish Explicit publish/draft switch, or
	 *                                     `null` when the caller has none to
	 *                                     offer (the client sent no fifth
	 *                                     param). `null` here is not "false" —
	 *                                     it means `draft` is left unset below
	 *                                     unless `post_status` says otherwise.
	 *
	 * @return array<string,mixed>
	 */
	public function toObject(array $struct, ?bool $publish, bool $isNew): array
	{
		$fields = [];

		if (array_key_exists('title', $struct)) {
			$fields['title'] = (string)$struct['title'];
		}

		if (array_key_exists('description', $struct)) {
			$fields['content'] = $this->relativizeUrls((string)$struct['description']);
		}

		if (array_key_exists('mt_excerpt', $struct)) {
			$fields['summary'] = (string)$struct['mt_excerpt'];
		}

		if (array_key_exists('mt_text_more', $struct)) {
			$fields['extra'] = $this->relativizeUrls((string)$struct['mt_text_more']);
		}

		if (array_key_exists('mt_keywords', $struct)) {
			$fields['tags'] = $this->splitKeywords($struct['mt_keywords']);
		}

		// A present-but-non-array value (e.g. `categories: null`) yields an empty
		// list rather than dropping the key, matching mt_keywords/tags below —
		// consistent "client sent it, we just couldn't read anything out of it"
		// handling rather than one field vanishing and its sibling not.
		if (array_key_exists('categories', $struct)) {
			$fields['categories'] = $this->stringList($struct['categories']);
		}

		if (array_key_exists('sticky', $struct)) {
			$fields['featured'] = (bool)$struct['sticky'];
		}

		$date = $this->normalizeDate($struct);
		if ($date !== null) {
			$fields['date'] = $date;
		}

		// wp_slug is honoured on create only. In T3 the id IS the storage
		// location — uploaded files live under it and it drives the public URL —
		// so accepting a rename here would mean delete-and-recreate, breaking
		// file paths and inbound links. Renaming stays an admin operation.
		if ($isNew && is_string($struct['wp_slug'] ?? null) && trim($struct['wp_slug']) !== '') {
			$fields['id'] = SlugData::slugify(trim($struct['wp_slug']));
		}

		$draft = $this->isDraft($struct, $publish);
		if ($draft !== null) {
			$fields['draft'] = $draft;
		}

		// Deliberately never mapped: wp_post_thumbnail (media is unsupported in
		// v1, and reading an empty value as "remove the image" is exactly how a
		// round-trip edit destroys an admin-set hero image), wp_password,
		// mt_allow_comments, mt_allow_pings, custom_fields.

		return $fields;
	}

	/**
	 * @param array<string,mixed> $object
	 *
	 * @return array<string,mixed>
	 */
	public function toStruct(array $object, CollectionData $collection): array
	{
		$id    = (string)($object['id'] ?? '');
		$local = $this->localDate($object['date'] ?? null);
		$url   = $this->urlBuilder->buildUrl($collection, $object);

		return [
			'postid'                 => $id,
			'wp_slug'                => $id,
			'title'                  => (string)($object['title'] ?? ''),
			'description'            => $this->absolutizeUrls((string)($object['content'] ?? '')),
			'mt_excerpt'             => (string)($object['summary'] ?? ''),
			'mt_text_more'           => $this->absolutizeUrls((string)($object['extra'] ?? '')),
			'mt_keywords'            => implode(', ', $this->stringList($object['tags'] ?? [])),
			'categories'             => $this->stringList($object['categories'] ?? []),
			'post_status'            => ($object['draft'] ?? false) ? 'draft' : 'publish',
			'sticky'                 => (bool)($object['featured'] ?? false),
			'wp_author_display_name' => (string)($object['author'] ?? ''),
			'dateCreated'            => $local,
			'date_created_gmt'       => $local->setTimezone(new \DateTimeZone('UTC')),
			'link'                   => $url,
			'permaLink'              => $url,
		];
	}

	public function titleSlug(string $title): string
	{
		$slug = SlugData::slugify($title);

		return $slug !== '' ? $slug : 'post';
	}

	/**
	 * The `wp.*` dialect's read-side post struct (wp.getPost / wp.getPosts
	 * entries). Deliberately built from the same primitives as toStruct()
	 * above — absolutizeUrls(), localDate(), stringList() — so the two
	 * dialects can never quietly drift apart on URL rewriting, timezone
	 * handling, or what counts as an extended entry.
	 *
	 * @param array<string,mixed> $object
	 *
	 * @return array<string,mixed>
	 */
	public function toWpStruct(array $object, CollectionData $collection): array
	{
		$id       = (string)($object['id'] ?? '');
		$local    = $this->localDate($object['date'] ?? null);
		$modified = $this->localDate($object['updated'] ?? $object['date'] ?? null);
		$url      = $this->urlBuilder->buildUrl($collection, $object);

		$content  = $this->absolutizeUrls((string)($object['content'] ?? ''));
		$rawExtra = (string)($object['extra'] ?? '');
		// "extra is non-empty" is judged on the raw field, not the absolutized
		// one — absolutizeUrls() never turns an empty string into a non-empty
		// one, but checking the source value keeps this rule readable next to
		// the write-side split() below, which also judges the raw struct.
		//
		// The rejoin always uses the bare `<!--more-->` literal, never whatever
		// variant (teaser text, extra whitespace) the client originally wrote —
		// `extra` is a T3 schema field shared verbatim with the metaWeblog
		// dialect's `mt_text_more` and the admin's own "Extended Entry" editor,
		// so storing the original marker text inside it would leak
		// `<!--more Read on-->` into both of those. There is no field to stash
		// a marker in without a schema change, so this is a deliberate
		// normalization, not an oversight: the split itself (which text lands
		// in `content` vs. `extra`) round-trips exactly; the marker's own
		// wording does not.
		$postContent = trim($rawExtra) !== ''
			? $content . '<!--more-->' . $this->absolutizeUrls($rawExtra)
			: $content;

		$terms = [];
		foreach ($this->stringList($object['categories'] ?? []) as $name) {
			$terms[] = $this->termStruct($name, 'category');
		}
		foreach ($this->stringList($object['tags'] ?? []) as $name) {
			$terms[] = $this->termStruct($name, 'post_tag');
		}

		return [
			'post_id'           => $id,
			'post_title'        => (string)($object['title'] ?? ''),
			'post_content'      => $postContent,
			'post_excerpt'      => (string)($object['summary'] ?? ''),
			'post_status'       => ($object['draft'] ?? false) ? 'draft' : 'publish',
			'post_type'         => 'post',
			'post_name'         => $id,
			'post_author'       => (string)($object['author'] ?? ''),
			'post_date'         => $local,
			'post_date_gmt'     => $local->setTimezone(new \DateTimeZone('UTC')),
			'post_modified'     => $modified,
			'post_modified_gmt' => $modified->setTimezone(new \DateTimeZone('UTC')),
			'link'              => $url,
			'sticky'            => (bool)($object['featured'] ?? false),
			// T3 has no comment system at all, so both are permanently 'closed'
			// rather than reflecting a setting that does not exist.
			'comment_status'    => 'closed',
			'ping_status'       => 'closed',
			'post_password'     => '',
			'post_parent'       => '0',
			'menu_order'        => 0,
			'custom_fields'     => [],
			'enclosure'         => [],
			'terms'             => $terms,
		];
	}

	/**
	 * The `wp.*` dialect's write-side mapping (wp.newPost / wp.editPost
	 * `content_struct`). Same "absent means absent" contract as toObject()
	 * above and for the same reason: WordPress's struct has no concept of
	 * `image`, `gallery`, `media` or any custom field, so a text-only edit
	 * from a writing app must never invent a value for one of them.
	 *
	 * @param array<string,mixed> $struct
	 * @param bool|null           $publish          Same meaning as toObject()'s
	 *                                               $publish: an explicit switch,
	 *                                               or null when the caller has
	 *                                               none to offer. The wp.*
	 *                                               dialect carries no separate
	 *                                               publish parameter at all —
	 *                                               `post_status` inside the
	 *                                               struct is the only signal —
	 *                                               so callers pass null on edit
	 *                                               (never invent a status) and
	 *                                               true on create (WordPress's
	 *                                               newPost default), mirroring
	 *                                               PostWriteHandler's
	 *                                               metaWeblog calls.
	 * @param bool                $hasExtendedEntry Whether the post ALREADY on
	 *                                               disk has a non-empty `extra`
	 *                                               field. Always `false` when
	 *                                               `$isNew` — a create has no
	 *                                               existing post to consult.
	 *                                               See splitExtendedEntry()'s
	 *                                               call below for why this
	 *                                               gates the split.
	 *
	 * @return array<string,mixed>
	 */
	public function fromWpStruct(array $struct, ?bool $publish, bool $isNew, bool $hasExtendedEntry): array
	{
		$fields = [];

		if (array_key_exists('post_title', $struct)) {
			$fields['title'] = (string)$struct['post_title'];
		}

		if (array_key_exists('post_content', $struct)) {
			$postContent = (string)$struct['post_content'];

			// Only split when the post we are writing already carries its own
			// extended entry. A `<!--more-->` marker WordPress itself put inline
			// in `post_content` — an imported post, whose `content` field holds
			// WXR `content:encoded` verbatim with `extra` empty (see
			// WordpressImporter) — is not ours to interpret. Splitting on it
			// unconditionally was the bug: a title-only edit through this
			// dialect echoes the post's full, pre-existing `post_content` back
			// (that's how the wire protocol works), so an unconditional split
			// would truncate `content` to the teaser and move the rest into
			// `extra` on every such edit, silently disappearing the body below
			// the marker from any template that only renders `content`. On
			// create there is no existing post to check at all, so the whole
			// body is stored as `content` and `extra` is left unset — matching
			// both WordPress's own inline storage and what our importer does.
			if ($hasExtendedEntry) {
				[$content, $extra] = $this->splitExtendedEntry($postContent);
				$fields['content'] = $this->relativizeUrls($content);

				// No marker found means $extra is null: leave the key OUT of the
				// result entirely (not even set to ''), so an existing extended
				// entry survives an edit whose post_content never mentioned it.
				if ($extra !== null) {
					$fields['extra'] = $this->relativizeUrls($extra);
				}
			} else {
				$fields['content'] = $this->relativizeUrls($postContent);
			}
		}

		if (array_key_exists('post_excerpt', $struct)) {
			$fields['summary'] = (string)$struct['post_excerpt'];
		}

		if (array_key_exists('sticky', $struct)) {
			$fields['featured'] = (bool)$struct['sticky'];
		}

		if (is_array($struct['terms_names'] ?? null)) {
			$terms = $struct['terms_names'];

			if (array_key_exists('category', $terms)) {
				$fields['categories'] = $this->stringList($terms['category']);
			}

			if (array_key_exists('post_tag', $terms)) {
				$fields['tags'] = $this->stringList($terms['post_tag']);
			}
		}

		$date = $this->normalizeDateFrom($struct, 'post_date_gmt', 'post_date');
		if ($date !== null) {
			$fields['date'] = $date;
		}

		// post_name is honoured on create only, for the same reason wp_slug is
		// in toObject(): the id IS the storage location in T3, so accepting a
		// rename here would mean delete-and-recreate. Renaming stays an admin
		// operation.
		if ($isNew && is_string($struct['post_name'] ?? null) && trim($struct['post_name']) !== '') {
			$fields['id'] = SlugData::slugify(trim($struct['post_name']));
		}

		$draft = $this->isDraft($struct, $publish);
		if ($draft !== null) {
			$fields['draft'] = $draft;
		}

		// Deliberately never mapped: post_thumbnail (media is unsupported, and
		// reading an empty value as "remove the image" is exactly how a
		// round-trip edit destroys an admin-set hero image), post_author,
		// post_password, custom_fields, comment_status, ping_status, terms
		// (the legacy raw-term array — terms_names is the write-capable form),
		// enclosure.

		return $fields;
	}

	/**
	 * Shared WP term struct — the same shape whether it is describing a
	 * single post's categories/tags (toWpStruct()'s `terms`) or the
	 * collection-wide distinct values `wp.getTerms` reports. T3 has no
	 * integer term ids, so the name doubles as `term_id` and
	 * `term_taxonomy_id`; `slug` is left as the bare name rather than a
	 * slugified variant, matching the existing wp.getTags dialect
	 * (TaxonomyHandler::getTags) so the two do not report different slugs
	 * for the same tag.
	 *
	 * @return array<string,mixed>
	 */
	public function termStruct(string $name, string $taxonomy): array
	{
		return [
			'term_id'          => $name,
			'name'             => $name,
			'slug'             => $name,
			'taxonomy'         => $taxonomy,
			'description'      => '',
			'parent'           => '0',
			'count'            => 0,
			'term_group'       => '0',
			'term_taxonomy_id' => $name,
		];
	}

	/**
	 * Expand our own path-relative upload URLs to absolute ones so a client's
	 * editor can render image previews. Relativizes first, which makes this
	 * idempotent — content already holding absolute URLs is unchanged.
	 *
	 * A domain-root install (`config->api` with no path component, e.g.
	 * `https://mysite.com`) has an empty `pathBase()` — `''` is still a valid
	 * base (it means "root-relative"), so we no longer bail out on it; only a
	 * genuinely unconfigured base (`$full === $path`, both empty) skips the
	 * rewrite.
	 *
	 * Because an empty path base makes the search string as generic as
	 * `/imageworks/upload/`, the replacement only fires immediately after a
	 * character that can legitimately precede a URL start: an opening quote
	 * (`"`/`'`, HTML attributes), an opening parenthesis (markdown link
	 * targets), whitespace (a bare URL in plain text), or the very start of
	 * the string. It deliberately does NOT fire mid-host — in a third-party
	 * URL like `https://othersite.com/imageworks/upload/pic.jpg` the
	 * character immediately before `/imageworks` is `m`, which is in none of
	 * those classes, so the URL is left untouched rather than spliced into a
	 * mangled double-host URL. An earlier version of this method anchored on
	 * the opening quote only, which is safe but too narrow: it left a bare
	 * unquoted URL (plain text, or a markdown link target) stripped of its
	 * host with no way back — strictly worse than not rewriting at all,
	 * since that URL worked before this method ever ran. `config->api` is
	 * operator-controlled but may contain regex-significant characters, so
	 * the interpolated needle is `preg_quote()`-d; the replacement runs
	 * through a callback (not a plain replacement string) so a `$` or `\` in
	 * the URL can never be misread as a backreference.
	 */
	public function absolutizeUrls(string $html): string
	{
		$full = $this->fullBase();
		$path = $this->pathBase();

		if ($html === '' || $full === $path) {
			return $html;
		}

		$html = $this->relativizeUrls($html);

		foreach (self::UPLOAD_PREFIXES as $prefix) {
			$needle  = $path . '/' . $prefix;
			$pattern = '/(^|["\'(\s])' . preg_quote($needle, '/') . '/';

			$html = (string)preg_replace_callback(
				$pattern,
				static fn (array $matches): string => $matches[1] . $full . '/' . $prefix,
				$html
			);
		}

		return $html;
	}

	/**
	 * Collapse absolute upload URLs back to path-relative before storing, so
	 * content stays portable across domains (staging → production keeps working).
	 *
	 * No quote-anchoring is needed here: the search string always includes the
	 * full scheme+host, which a third-party URL cannot share, so a plain
	 * substring match is already safe.
	 */
	public function relativizeUrls(string $html): string
	{
		$full = $this->fullBase();
		$path = $this->pathBase();

		if ($html === '' || $full === $path) {
			return $html;
		}

		foreach (self::UPLOAD_PREFIXES as $prefix) {
			$html = str_replace($full . '/' . $prefix, $path . '/' . $prefix, $html);
		}

		return $html;
	}

	/**
	 * @param array<string,mixed> $struct
	 *
	 * @return bool|null `null` means "leave `draft` unset": no `post_status`
	 *                    in the struct and no explicit publish flag supplied
	 */
	private function isDraft(array $struct, ?bool $publish): ?bool
	{
		$status = is_string($struct['post_status'] ?? null) ? strtolower(trim($struct['post_status'])) : '';

		if ($status === '') {
			return $publish === null ? null : !$publish;
		}

		// `private` has no T3 equivalent and `future` cannot auto-publish, so both
		// stay drafts — failing hidden rather than accidentally public.
		return $status !== 'publish';
	}

	/**
	 * @param array<string,mixed> $struct
	 *
	 * @return string|null ISO 8601 in the site timezone, or null when absent
	 */
	private function normalizeDate(array $struct): ?string
	{
		return $this->normalizeDateFrom($struct, 'date_created_gmt', 'dateCreated');
	}

	/**
	 * Shared by normalizeDate() (metaWeblog's `dateCreated`/`date_created_gmt`
	 * keys) and fromWpStruct() (wp's `post_date`/`post_date_gmt` keys) — same
	 * GMT-preferred timezone rule either dialect uses, kept in one place so the
	 * two can never drift apart on it.
	 *
	 * @param array<string,mixed> $struct
	 *
	 * @return string|null ISO 8601 in the site timezone, or null when absent
	 */
	private function normalizeDateFrom(array $struct, string $gmtKey, string $localKey): ?string
	{
		$zone = new \DateTimeZone($this->config->timezone);

		// Prefer the GMT field: the local-named field is client-local with no
		// zone, which is the classic source of posts landing hours off.
		$gmt = $struct[$gmtKey] ?? null;
		if ($gmt instanceof \DateTimeInterface) {
			return \DateTimeImmutable::createFromInterface($gmt)
				->setTimezone($zone)
				->format('c');
		}

		$local = $struct[$localKey] ?? null;
		if ($local instanceof \DateTimeInterface) {
			return \DateTimeImmutable::createFromInterface($local)->setTimezone($zone)->format('c');
		}

		foreach ([$gmtKey, $localKey] as $key) {
			if (is_string($struct[$key] ?? null) && trim($struct[$key]) !== '') {
				try {
					return (new \DateTimeImmutable($struct[$key]))->setTimezone($zone)->format('c');
				} catch (\Exception) {
					return null;
				}
			}
		}

		return null;
	}

	/**
	 * WordPress recognizes more than the bare `<!--more-->` literal: a teaser
	 * variant carries text after the keyword (`<!--more Read on-->`), and
	 * whitespace inside the comment is tolerated (`<!-- more -->`). This
	 * matches any HTML comment whose content starts with the word `more` —
	 * `\b` after it rules out a comment like `<!--moreover-->` that merely
	 * starts with the same four letters — split on the FIRST such marker
	 * only, matching how the bare-literal version behaved.
	 */
	private const MORE_MARKER_PATTERN = '/<!--\s*more\b.*?-->/s';

	/**
	 * Split a wp.* `post_content` on its first extended-entry marker (see
	 * MORE_MARKER_PATTERN above). Only called from fromWpStruct() when the post
	 * being written already has its own extended entry — this method itself
	 * has no way to know whether a marker it finds is genuinely ours to split
	 * on or just inline WordPress markup carried over from an import, which is
	 * why that decision is made by the caller before reaching here.
	 *
	 * The marker's own text (e.g. a custom teaser like "Read on", or the
	 * exact whitespace the author used) is deliberately NOT preserved here —
	 * see toWpStruct()'s use of the literal `<!--more-->` on the read side
	 * for why.
	 *
	 * @return array{0:string,1:string|null} [content, extra]. `extra` is
	 *                                       null when no marker was found —
	 *                                       the caller must leave the `extra`
	 *                                       key out entirely in that case, not
	 *                                       set it to ''.
	 */
	private function splitExtendedEntry(string $postContent): array
	{
		if (preg_match(self::MORE_MARKER_PATTERN, $postContent, $matches, PREG_OFFSET_CAPTURE) !== 1) {
			return [$postContent, null];
		}

		$marker = $matches[0][0];
		$offset = $matches[0][1];

		return [substr($postContent, 0, $offset), substr($postContent, $offset + strlen($marker))];
	}

	private function localDate(mixed $date): \DateTimeImmutable
	{
		$zone = new \DateTimeZone($this->config->timezone);

		if (is_string($date) && trim($date) !== '') {
			try {
				return (new \DateTimeImmutable($date))->setTimezone($zone);
			} catch (\Exception) {
				// fall through to now
			}
		}

		return new \DateTimeImmutable('now', $zone);
	}

	/** @return array<int,string> */
	private function splitKeywords(mixed $keywords): array
	{
		if (is_array($keywords)) {
			return $this->stringList($keywords);
		}

		$parts = array_map(trim(...), explode(',', (string)$keywords));

		return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
	}

	/** @return array<int,string> */
	private function stringList(mixed $value): array
	{
		if (!is_array($value)) {
			return [];
		}

		$list = array_map(static fn (mixed $item): string => trim((string)$item), $value);

		return array_values(array_filter($list, static fn (string $item): bool => $item !== ''));
	}

	private function fullBase(): string
	{
		return rtrim($this->config->api, '/');
	}

	private function pathBase(): string
	{
		return rtrim((string)parse_url($this->config->api, PHP_URL_PATH), '/');
	}
}
