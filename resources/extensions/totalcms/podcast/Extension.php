<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Podcast;

use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use TotalCMS\Domain\Feed\Service\FeedWriter;
use TotalCMS\Domain\Twig\Adapter\CollectionTwigAdapter;
use TotalCMS\Support\Config;
use Twig\Markup;
use Twig\TwigFunction;

// Bundled extensions don't ship their own composer autoloader. ExtensionManager
// only require_once's the entrypoint, so any sibling files must be loaded here.
require_once __DIR__ . '/PodcastFeedMapper.php';
require_once __DIR__ . '/PodcastFeed.php';
require_once __DIR__ . '/PodcastFeedAction.php';

/**
 * Podcasts as an extension: two schemas, one feed, two ways to publish it.
 *
 * The `schemas/` directory ships `podcast` (the show — a singleton
 * collection) and `podcast-episode`. A show record names its own episodes
 * collection, so a feed is identified by the show alone and a site can host
 * several: `/api/ext/totalcms/podcast/feed` for the collection named
 * `podcast`, `/feed/{show}` for any other. `podcast_feed(show)` renders the
 * same feed inside a page for sites that want it at an address of their own.
 *
 * The RSS itself — iTunes and Podcast Index tags — is core (`cms.feed.rss()`
 * with a `podcast` block); this extension only maps records onto it.
 */
class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
		$feed = new PodcastFeed(
			$context->get(CollectionTwigAdapter::class),
			$context->get(FeedWriter::class),
			$context->get(Config::class),
		);

		// A page rendering the feed is the feed's address, so the request path is
		// the default `self`; the option overrides it, and outside a request (CLI,
		// tests) the route the extension serves the show at is used.
		$context->addTwigFunction(new TwigFunction(
			'podcast_feed',
			static function (string $show = 'podcast', array $options = []) use ($feed): Markup {
				$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
				if (trim((string)($options['self'] ?? '')) === '' && is_string($path) && $path !== '') {
					$options['self'] = $path;
				}

				return new Markup($feed->render($show, $options), 'UTF-8');
			},
			['is_safe' => ['html']],
		));

		$context->addPublicRoutes(static function ($routes) use ($feed): void {
			$action = new PodcastFeedAction($feed);
			$routes->get('/feed', $action);
			$routes->get('/feed/{show}', $action);
		});
	}

	public function boot(ExtensionContext $context): void
	{
	}
}
