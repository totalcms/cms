<?php

namespace TotalCMS\Action\Sitemap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Sitemap\Service\SitemapBuilder;
use TotalCMS\Renderer\XmlRenderer;

final readonly class SitemapFactoryAction
{
	public function __construct(
		private XmlRenderer $xmlRenderer,
		private SitemapBuilder $sitemapBuilder,
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

		$xml = $this->sitemapBuilder->buildSitemap($collection, $params);

		return $this->xmlRenderer->xml($response, $xml);
	}
}
