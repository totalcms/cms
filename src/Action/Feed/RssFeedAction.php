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

		// Allowed Parameters
		// Feed MetaData: link image name description language
		// Object Field Mapping: title content media author date draft

		$params['rssurl'] = strval($request->getUri());

		$this->rssBuilder->setFieldMap($params);
		$xml = $this->rssBuilder->buildFeed($collection, $params);

		return $this->xmlRenderer->xml($response, $xml);
	}
}
