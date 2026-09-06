<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

use TotalCMS\Domain\Feed\Service\FeedWriter;
use TotalCMS\Domain\Feed\Service\PodcastFeedMapper;
use TotalCMS\Support\Config;
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
		private CollectionTwigAdapter $collections,
		private Config $config,
	) {
	}

	/**
	 * A complete podcast feed from a `podcast` show collection (a Single Object
	 * Collection) and a `podcast-episode` collection. Sugar over `rss()`: the
	 * show and the episode index rows are fetched, mapped by PodcastFeedMapper,
	 * and rendered. Drafts, future-dated episodes and episodes without audio
	 * are left out; newest first.
	 *
	 * @param array<string,mixed> $options `self` (feed URL, default /podcast.xml),
	 *                                     `link` (site URL, default /), `language`, `copyright`
	 *
	 * @throws \DomainException when the show record is missing
	 */
	public function podcast(string $showCollection = 'podcast', string $episodesCollection = 'episodes', array $options = []): Markup
	{
		$show = $this->collections->object($showCollection, $showCollection);
		if ($show === []) {
			throw new \DomainException(sprintf(
				"cms.feed.podcast: no show record found in '%s'. Create it as a Single Object Collection from the podcast schema and fill in the show.",
				$showCollection,
			));
		}

		$mapped = (new PodcastFeedMapper($this->config))->map(
			$showCollection,
			$show,
			$episodesCollection,
			array_values($this->collections->objects($episodesCollection)),
			$options,
			fn (array $episode): string => $this->collections->canonicalObjectUrl($episodesCollection, $episode),
		);

		return $this->rss($mapped['meta'], $mapped['items']);
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
