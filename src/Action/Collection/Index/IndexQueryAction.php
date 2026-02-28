<?php

declare(strict_types=1);

namespace TotalCMS\Action\Collection\Index;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\QueryActionRenderer;
use TotalCMS\Domain\Index\Service\IndexQueryService;
use TotalCMS\Support\Config;

/**
 * Paginated collection query endpoint.
 *
 * Supports three output formats:
 * - json (default): Fractal-formatted JSON with pagination metadata
 * - html: Server-rendered Twig templates with HTMX trigger for next page
 * - csv: CSV export of paginated index data
 */
readonly class IndexQueryAction
{
	public function __construct(
		private IndexQueryService $queryService,
		private QueryActionRenderer $renderer,
		private Config $config,
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
		$format     = $params['format'] ?? 'json';
		$result     = $this->queryService->query($collection, $params);
		$baseUrl    = $this->config->api . '/collections/' . $collection . '/query';

		// Pass collection name for table format rendering
		$params['_collection'] = $collection;

		return $this->renderer->render($request, $response, $result, $params, $format, $baseUrl, 'collection-' . $collection);
	}
}
