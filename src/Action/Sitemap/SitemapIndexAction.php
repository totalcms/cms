<?php

declare(strict_types=1);

namespace TotalCMS\Action\Sitemap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Sitemap\Service\SitemapIndexBuilder;
use TotalCMS\Renderer\XmlRenderer;

/**
 * Serves the sitemap index. Available at both `/sitemap` and `/sitemap.xml`,
 * with the latter being the conventional path crawlers expect to find via
 * robots.txt.
 */
readonly class SitemapIndexAction
{
	public function __construct(
		private XmlRenderer $xmlRenderer,
		private SitemapIndexBuilder $sitemapIndexBuilder,
	) {
	}

	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
	): ResponseInterface {
		$xml = $this->sitemapIndexBuilder->buildIndex();

		return $this->xmlRenderer->xml($response, $xml);
	}
}
