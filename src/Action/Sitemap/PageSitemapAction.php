<?php

declare(strict_types=1);

namespace TotalCMS\Action\Sitemap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Sitemap\Service\PageSitemapBuilder;
use TotalCMS\Renderer\XmlRenderer;

readonly class PageSitemapAction
{
	public function __construct(
		private XmlRenderer $xmlRenderer,
		private PageSitemapBuilder $sitemapBuilder,
	) {
	}

	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
	): ResponseInterface {
		$params = $request->getQueryParams();
		$xml    = $this->sitemapBuilder->buildSitemap($params);

		return $this->xmlRenderer->xml($response, $xml);
	}
}
