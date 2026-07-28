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
 * with what they get here and nothing else is touched.
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
	 *
	 * @return array<string,mixed>
	 */
	public function toObject(array $struct, bool $publish, bool $isNew): array
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

		if (array_key_exists('categories', $struct) && is_array($struct['categories'])) {
			$fields['categories'] = array_values(array_map(
				static fn (mixed $category): string => trim((string)$category),
				$struct['categories']
			));
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

		$fields['draft'] = $this->isDraft($struct, $publish);

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
	 * Expand our own path-relative upload URLs to absolute ones so a client's
	 * editor can render image previews. Relativizes first, which makes this
	 * idempotent — content already holding absolute URLs is unchanged.
	 */
	public function absolutizeUrls(string $html): string
	{
		$full = $this->fullBase();
		$path = $this->pathBase();

		if ($html === '' || $path === '' || $full === $path) {
			return $html;
		}

		$html = $this->relativizeUrls($html);

		foreach (self::UPLOAD_PREFIXES as $prefix) {
			$html = str_replace($path . '/' . $prefix, $full . '/' . $prefix, $html);
		}

		return $html;
	}

	/**
	 * Collapse absolute upload URLs back to path-relative before storing, so
	 * content stays portable across domains (staging → production keeps working).
	 */
	public function relativizeUrls(string $html): string
	{
		$full = $this->fullBase();
		$path = $this->pathBase();

		if ($html === '' || $path === '' || $full === $path) {
			return $html;
		}

		foreach (self::UPLOAD_PREFIXES as $prefix) {
			$html = str_replace($full . '/' . $prefix, $path . '/' . $prefix, $html);
		}

		return $html;
	}

	/** @param array<string,mixed> $struct */
	private function isDraft(array $struct, bool $publish): bool
	{
		$status = is_string($struct['post_status'] ?? null) ? strtolower(trim($struct['post_status'])) : '';

		if ($status === '') {
			return !$publish;
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
		$zone = new \DateTimeZone($this->config->timezone);

		// Prefer the GMT field: `dateCreated` is client-local with no zone, which
		// is the classic source of posts landing hours off.
		$gmt = $struct['date_created_gmt'] ?? null;
		if ($gmt instanceof \DateTimeInterface) {
			return \DateTimeImmutable::createFromInterface($gmt)
				->setTimezone($zone)
				->format('c');
		}

		$local = $struct['dateCreated'] ?? null;
		if ($local instanceof \DateTimeInterface) {
			return \DateTimeImmutable::createFromInterface($local)->setTimezone($zone)->format('c');
		}

		foreach (['date_created_gmt', 'dateCreated'] as $key) {
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

		$parts = array_map('trim', explode(',', (string)$keywords));

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
