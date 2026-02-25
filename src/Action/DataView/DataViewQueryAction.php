<?php

declare(strict_types=1);

namespace TotalCMS\Action\DataView;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\QueryActionRenderer;
use TotalCMS\Domain\DataView\Service\DataViewQueryService;
use TotalCMS\Support\Config;

/**
 * Paginated DataView query endpoint.
 *
 * Supports three output formats:
 * - json (default): Fractal-formatted JSON with pagination metadata
 * - html: Server-rendered Twig templates with HTMX trigger for next page
 * - csv: CSV export of paginated DataView data
 */
readonly class DataViewQueryAction
{
	public function __construct(
		private DataViewQueryService $queryService,
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
		$viewId  = $args['id'];
		$params  = $request->getQueryParams();
		$format  = $params['format'] ?? 'json';
		$result  = $this->queryService->query($viewId, $params);
		$baseUrl = $this->config->api . '/dataviews/' . $viewId . '/query';

		return $this->renderer->render($request, $response, $result, $params, $format, $baseUrl, 'dataview-' . $viewId);
	}
}
