<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Podcast;

use TotalCMS\Domain\Feed\Service\FeedWriter;
use TotalCMS\Domain\Twig\Adapter\CollectionTwigAdapter;
use TotalCMS\Support\Config;

/**
 * One show → one RSS document. Shared by the public route and the Twig
 * function so the two can never render different feeds for the same show.
 */
final readonly class PodcastFeed
{
	public function __construct(
		private CollectionTwigAdapter $collections,
		private FeedWriter $writer,
		private Config $config,
	) {
	}

	/** The address the extension serves a show's feed at, site-relative. */
	public static function routePath(string $showCollection): string
	{
		return '/api/ext/totalcms/podcast/feed' . ($showCollection === 'podcast' ? '' : '/' . $showCollection);
	}

	/**
	 * @param string              $showCollection the singleton collection holding the show record
	 * @param array<string,mixed> $options        link, language, copyright, now, and `self` — the feed's own
	 *                                            address, which apps re-fetch with and the Podcast Index GUID
	 *                                            derives from. Defaults to the route the extension serves the
	 *                                            show at; a page rendering the feed passes its own URL.
	 *
	 * @throws \DomainException when the show has no record or names no episodes collection
	 */
	public function render(string $showCollection, array $options = []): string
	{
		if (trim((string)($options['self'] ?? '')) === '') {
			$options['self'] = self::routePath($showCollection);
		}

		$show = $this->collections->object($showCollection, $showCollection);
		if ($show === []) {
			throw new \DomainException(sprintf(
				"No show record found in '%s'. Create it as a Single Object Collection from the podcast schema and fill in the show.",
				$showCollection,
			));
		}

		$episodesCollection = trim((string)($show['episodes'] ?? ''));
		if ($episodesCollection === '') {
			throw new \DomainException(sprintf(
				"The show in '%s' names no Episodes Collection. Pick one on the show record — a collection made from the podcast-episode schema.",
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

		return $this->writer->write($mapped['meta'], $mapped['items'], 'rss');
	}
}
