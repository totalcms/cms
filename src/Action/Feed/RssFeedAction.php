<?php

namespace TotalCMS\Action\Feed;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Feed\Exception\FeedDisabledException;
use TotalCMS\Domain\Feed\Service\RssBuilder;
use TotalCMS\Renderer\XmlRenderer;

readonly class RssFeedAction
{
	public function __construct(
		private XmlRenderer $xmlRenderer,
		private RssBuilder $rssBuilder,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$collection = $args['collection'];
		// Only what a request may override, and only string values, reach the builder.
		$params = array_filter(
			array_intersect_key($request->getQueryParams(), array_flip(RssBuilder::OVERRIDES)),
			is_string(...),
		);

		$params['rssurl'] = strval($request->getUri());

		try {
			$xml = $this->rssBuilder->buildFeed($collection, $params);
		} catch (FeedDisabledException) {
			// No feed here: not enabled, or no such collection. One answer for both.
			return $response->withStatus(404);
		}

		return $this->xmlRenderer->xml($response, $xml);
	}
}
