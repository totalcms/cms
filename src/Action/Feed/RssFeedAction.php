<?php

namespace TotalCMS\Action\Feed;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Feed\Service\RssBuilder;
use TotalCMS\Renderer\XmlRenderer;

readonly class RssFeedAction
{
	/**
	 * Feed metadata, object filters, and the object field mapping. `draft` is
	 * not mappable: drafts are always left out (RssBuilder::isDraft()).
	 */
	private const ALLOWED_PARAMS = [
		'name', 'description', 'link', 'image', 'language',
		'include', 'exclude', 'limit',
		'title', 'content', 'media', 'author', 'date',
	];

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
		// Only the documented keys, and only string values, reach the builder.
		// The query string used to pass through whole.
		$params = array_filter(
			array_intersect_key($request->getQueryParams(), array_flip(self::ALLOWED_PARAMS)),
			is_string(...),
		);

		$params['rssurl'] = strval($request->getUri());

		$this->rssBuilder->setFieldMap($params);
		$xml = $this->rssBuilder->buildFeed($collection, $params);

		return $this->xmlRenderer->xml($response, $xml);
	}
}
