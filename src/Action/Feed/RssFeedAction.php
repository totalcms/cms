<?php

namespace TotalCMS\Action\Feed;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Feed\Service\RssBuilder;
use TotalCMS\Renderer\XmlRenderer;

final class RssFeedAction
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
		$params     = $request->getQueryParams();

		$xml = $this->rssBuilder->buildFeed($collection, $params);

		return $this->xmlRenderer->xml($response, $xml);
	}
}
