<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Feed\Service;

use Laminas\Feed\Writer\Entry;
use Laminas\Feed\Writer\Feed;
use TotalCMS\Support\Config;

/**
 * Build an RSS or Atom document from a meta array and a list of item arrays.
 *
 * The engine behind `cms.feed.rss()` / `cms.feed.atom()`. Templates decide
 * what goes in a feed — filtering with `|filter`, ordering with `|sortBy`,
 * shaping each item with `|map` — and hand the result here. That split is
 * deliberate: the existing `/feed/rss/{collection}` endpoint maps fields by
 * name instead, which cannot compose a title from two fields or run content
 * through `|markdown`, and those are the two things feeds most often need.
 *
 * Ordering is the caller's; entries are emitted as given.
 *
 * Laminas handles the parts that are tedious and easy to get subtly wrong:
 * escaping, CDATA for HTML payloads, RFC-2822 dates, and the atom:link self
 * reference.
 */
readonly class FeedWriter
{
	public const FORMATS = ['rss', 'atom'];

	public function __construct(
		private Config $config,
	) {
	}

	/**
	 * @param array<string,mixed> $meta
	 * @param iterable<array<string,mixed>> $items
	 *
	 * @throws \DomainException on an unknown format or missing required meta
	 */
	public function write(array $meta, iterable $items, string $format = 'rss'): string
	{
		$format = strtolower($format);
		if (!in_array($format, self::FORMATS, true)) {
			throw new \DomainException(sprintf(
				'Unknown feed format "%s". Supported formats: %s.',
				$format,
				implode(', ', self::FORMATS),
			));
		}

		$feed = new Feed();
		$this->applyMeta($feed, $meta, $format);

		$latest = null;
		foreach ($items as $item) {
			$entry = $this->buildEntry($feed, $item, $format);
			$feed->addEntry($entry);

			$stamp  = $this->timestamp($item['date'] ?? null);
			$latest = $stamp !== null && ($latest === null || $stamp > $latest) ? $stamp : $latest;
		}

		// Both formats want a feed-level date. Default to the newest item so a
		// caller never has to restate what the items already say; Atom refuses
		// to render without one at all.
		$updated = $this->timestamp($meta['updated'] ?? null) ?? $latest ?? time();
		$feed->setDateModified($updated);

		try {
			return $feed->export($format);
		} catch (\Throwable $e) {
			throw new \DomainException('Could not render the feed: ' . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * @param array<string,mixed> $meta
	 *
	 * @throws \DomainException
	 */
	private function applyMeta(Feed $feed, array $meta, string $format): void
	{
		// Laminas throws its own terse errors for these, naming neither the
		// caller nor the fact that a template is what is missing a key.
		// Atom will not render without a self link, and rightly so: readers
		// use it to re-fetch. Defaulting it to `link` would hand every
		// subscriber a re-subscribe URL pointing at the HTML page instead of
		// the feed, which is worse than refusing.
		$required = ['title', 'link', 'description'];
		if ($format === 'atom') {
			$required[] = 'self';
		}

		foreach ($required as $key) {
			if (($meta[$key] ?? '') === '') {
				throw new \DomainException(sprintf(
					'cms.feed.%s: meta.%s is required.',
					$format,
					$key,
				));
			}
		}

		$feed->setTitle((string)$meta['title']);
		$feed->setLink($this->absolute((string)$meta['link']));
		$feed->setDescription((string)$meta['description']);

		// The self reference: rel="self" in RSS, and how a reader re-finds the
		// feed after the page that linked it changes.
		$self = (string)($meta['self'] ?? '');
		if ($self !== '') {
			$feed->setFeedLink($this->absolute($self), $format);
		}

		foreach (['language' => 'setLanguage', 'copyright' => 'setCopyright', 'generator' => 'setGenerator'] as $key => $setter) {
			$value = (string)($meta[$key] ?? '');
			if ($value !== '') {
				$feed->{$setter}($value);
			}
		}
	}

	/**
	 * @param array<string,mixed> $item
	 */
	private function buildEntry(Feed $feed, array $item, string $format): Entry
	{
		$entry = $feed->createEntry();
		$link  = $this->absolute((string)($item['link'] ?? ''));

		if (($item['title'] ?? '') !== '') {
			$entry->setTitle((string)$item['title']);
		}
		if ($link !== '') {
			$entry->setLink($link);
		}

		// A guid is an identity, not a location: readers re-announce every item
		// whose id changes. Callers should pass a stable one; the link is the
		// least-bad fallback.
		//
		// Atom is stricter — an entry id must be an IRI, so a short stable key
		// like `v3-5-0` is not legal there however good an identity it makes.
		// RSS keeps it (as a non-permalink guid); Atom falls back to the link,
		// which every feed generator does and which is at least a real URI.
		//
		// Only http(s) counts as already-an-IRI here. Laminas validates other
		// schemes — a `tag:` id in particular — through laminas-validator,
		// which is not a dependency, so letting one through would crash the
		// render at request time rather than produce a feed.
		$id = (string)($item['id'] ?? '');
		if ($format === 'atom' && !preg_match('#^https?://#i', $id)) {
			$id = '';
		}
		if ($id === '') {
			$id = $link;
		}
		if ($id !== '') {
			$entry->setId($id);
		}

		$stamp = $this->timestamp($item['date'] ?? null);
		if ($stamp !== null) {
			$entry->setDateModified($stamp);
			$entry->setDateCreated($stamp);
		}

		$content = (string)($item['content'] ?? '');
		$summary = (string)($item['summary'] ?? '');

		// RSS has one body slot, so content wins and summary fills in. Atom
		// carries both, and laminas maps description -> summary for it.
		if ($summary !== '') {
			$entry->setDescription($summary);
		} elseif ($content !== '') {
			$entry->setDescription($content);
		}
		if ($content !== '') {
			$entry->setContent($content);
		}

		$author = $item['author'] ?? null;
		if (is_string($author) && $author !== '') {
			$entry->addAuthor(['name' => $author]);
		} elseif (is_array($author) && ($author['name'] ?? '') !== '') {
			$entry->addAuthor(array_filter([
				'name'  => (string)$author['name'],
				'email' => (string)($author['email'] ?? ''),
				'uri'   => (string)($author['uri'] ?? ''),
			], static fn (string $v): bool => $v !== ''));
		}

		$this->applyMedia($entry, $item['media'] ?? null);

		return $entry;
	}

	/**
	 * Attach an enclosure.
	 *
	 * Accepts a bare URL, or a hash carrying what a URL cannot: `type` and
	 * `length`. RSS requires both, and T3 image and file field values already
	 * hold `mime` and `size`, so a template can supply real values rather than
	 * a guess and a zero.
	 *
	 * One enclosure per item — RSS 2.0 permits no more.
	 */
	private function applyMedia(Entry $entry, mixed $media): void
	{
		if (is_string($media)) {
			$media = ['url' => $media];
		}
		if (!is_array($media)) {
			return;
		}

		$url = $this->absolute((string)($media['url'] ?? $media['uri'] ?? ''));
		if ($url === '') {
			return;
		}

		$entry->setEnclosure([
			'uri'    => $url,
			'type'   => (string)($media['type'] ?? $media['mime'] ?? '') ?: MediaMimeGuesser::guess($url),
			'length' => (string)(int)($media['length'] ?? $media['size'] ?? 0),
		]);
	}

	/**
	 * A feed reader has no base to resolve against, so a relative URL is a
	 * broken one rather than merely untidy.
	 */
	private function absolute(string $url): string
	{
		if ($url === '' || str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
			return $url;
		}

		return 'https://' . $this->config->domain . '/' . ltrim($url, '/');
	}

	/** Accepts an ISO string, a timestamp, or a DateTimeInterface. */
	private function timestamp(mixed $date): ?int
	{
		if ($date instanceof \DateTimeInterface) {
			return $date->getTimestamp();
		}
		if (is_int($date)) {
			return $date;
		}
		if (is_string($date) && $date !== '') {
			return strtotime($date) ?: null;
		}

		return null;
	}
}
