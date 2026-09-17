<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Podcast;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/ext/totalcms/podcast/feed` and `/feed/{show}`.
 *
 * The feed without a page: enable the extension, fill in the show, hand this
 * address to the directories. A show that cannot render — no record, or no
 * episodes collection chosen — is a 404 that says which, rather than an
 * empty feed the directories would reject anyway.
 */
final readonly class PodcastFeedAction
{
	public function __construct(private PodcastFeed $feed)
	{
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
	{
		$show = trim((string)($args['show'] ?? ''));
		if ($show === '') {
			$show = 'podcast';
		}

		try {
			// The request is the feed's address: FeedWriter makes the path absolute on the site's origin.
			$xml = $this->feed->render($show, ['self' => $request->getUri()->getPath()]);
		} catch (\DomainException $e) {
			$response->getBody()->write($e->getMessage());

			return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
		}

		$response->getBody()->write($xml);

		return $response
			->withHeader('Content-Type', 'application/rss+xml; charset=utf-8')
			->withHeader('Cache-Control', 'public, max-age=300');
	}
}
