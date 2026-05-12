<?php

namespace TotalCMS\Action\Sitemap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Sitemap\Exception\SitemapDisabledException;
use TotalCMS\Domain\Sitemap\Service\SitemapBuilder;
use TotalCMS\Renderer\XmlRenderer;

readonly class SitemapFactoryAction
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

		// Backwards compatibility: remap 'filter' to 'include'
		if (isset($params['filter']) && !isset($params['include'])) {
			$params['include'] = $params['filter'];
			unset($params['filter']);
		}

		try {
			$xml = $this->sitemapBuilder->buildSitemap($collection, $params);
		} catch (SitemapDisabledException) {
			// Don't leak whether a disabled sitemap exists — return 404 like any
			// missing resource.
			return $response->withStatus(404);
		}

		return $this->xmlRenderer->xml($response, $xml);
	}
}
