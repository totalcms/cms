<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

use TotalCMS\Domain\Feed\Service\FeedWriter;
use Twig\Markup;

/**
 * Twig sub-adapter for building syndication feeds.
 *
 * Accessed in Twig as `cms.feed.rss()` and `cms.feed.atom()`.
 *
 * Both take the same two arguments — feed meta, and a list of items — so a
 * template can offer either format from one set of inputs:
 *
 *     {{ cms.feed.rss(
 *          {
 *            title:       'Total CMS Releases',
 *            link:        cms.builder.canonicalUrl('changelog'),
 *            self:        cms.builder.canonicalUrl('changelog-rss'),
 *            description: 'One entry per release.',
 *          },
 *          cms.collection.objects('changelog')|sortBy('date')|reverse|map(e => {
 *            title:   e.version ~ ' — ' ~ e.title,
 *            link:    cms.collection.canonicalObjectUrl('changelog', e),
 *            id:      e.id,
 *            date:    e.date,
 *            content: e.changelog|markdown,
 *          })
 *     ) }}
 *
 * Choosing and shaping the items stays in the template, where `|filter`,
 * `|sortBy` and `|map` already live. The alternative — naming which field
 * means "title" and which means "content", as `/feed/rss/{collection}` does —
 * cannot compose a title out of two fields or run content through `|markdown`,
 * and those are the two things a feed most often needs.
 */
readonly class FeedTwigAdapter
{
	public function __construct(
		private FeedWriter $writer,
	) {
	}

	/**
	 * Render an RSS 2.0 feed from feed details and a list of items.
	 *
	 * `meta` requires `title`, `link` and `description`; each item takes
	 * `title`, `link`, `date` and `content`, plus optional `id`, `summary`,
	 * `author` and `media`. Items are emitted in the order given, so sort
	 * before mapping.
	 *
	 * @param array<string,mixed> $meta
	 * @param iterable<array<string,mixed>> $items
	 */
	public function rss(array $meta, iterable $items = []): Markup
	{
		return $this->markup($meta, $items, 'rss');
	}

	/**
	 * Render an Atom 1.0 feed from the same arguments as `rss()`.
	 *
	 * Atom additionally requires `meta.self` — readers use it to re-fetch, so
	 * there is no safe default to invent.
	 *
	 * @param array<string,mixed> $meta
	 * @param iterable<array<string,mixed>> $items
	 */
	public function atom(array $meta, iterable $items = []): Markup
	{
		return $this->markup($meta, $items, 'atom');
	}

	/**
	 * Returned as Markup rather than a plain string so the document survives a
	 * template that has not turned autoescape off — otherwise every angle
	 * bracket in the feed comes back as &lt;.
	 *
	 * @param array<string,mixed> $meta
	 * @param iterable<array<string,mixed>> $items
	 */
	private function markup(array $meta, iterable $items, string $format): Markup
	{
		return new Markup($this->writer->write($meta, $items, $format), 'UTF-8');
	}
}
