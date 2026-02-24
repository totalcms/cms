<?php

declare(strict_types=1);

namespace TotalCMS\Action\Collection\Index;

use League\Csv\Writer;
use League\Fractal\Manager as FractalManager;
use League\Fractal\Resource\Collection as FractalCollection;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use TotalCMS\Renderer\OffsetPaginator;
use TotalCMS\Domain\Index\Service\IndexQueryService;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Twig\Service\HtmxRenderer;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\RawRenderer;
use TotalCMS\Support\Config;
use TotalCMS\Transformer\QueryObjectTransformer;

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
		private JsonRenderer $jsonRenderer,
		private RawRenderer $rawRenderer,
		private IndexQueryService $queryService,
		private HtmxRenderer $htmxRenderer,
		private TwigEngine $twigEngine,
		private Config $config,
		private EditionFeatureService $editionFeatures,
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

		return match ($format) {
			'html' => $this->renderHtml($request, $response, $collection, $params),
			'csv'  => $this->renderCsv($response, $collection, $params),
			default => $this->renderJson($response, $collection, $params),
		};
	}

	/**
	 * @param array<string,string> $params
	 */
	private function renderJson(
		ResponseInterface $response,
		string $collection,
		array $params,
	): ResponseInterface {
		$result = $this->queryService->query($collection, $params);

		$resource  = new FractalCollection($result->items, new QueryObjectTransformer());
		$baseUrl   = $this->config->api . '/collections/' . $collection . '/query';
		$paginator = new OffsetPaginator($result->total, $result->limit, $result->offset, $baseUrl);
		$resource->setPaginator($paginator);

		$data     = (new FractalManager())->createData($resource)->toArray();
		$response = $result->withPaginationHeaders($response);

		return $this->jsonRenderer->json($response, $data);
	}

	/**
	 * @param array<string,string> $params
	 */
	private function renderHtml(
		ServerRequestInterface $request,
		ResponseInterface $response,
		string $collection,
		array $params,
	): ResponseInterface {
		$template = $params['template'] ?? '';
		if ($template === '') {
			throw new HttpBadRequestException($request, 'The "template" parameter is required for HTML format.');
		}

		if (!$this->editionFeatures->can(EditionFeature::TEMPLATES)) {
			throw new HttpForbiddenException($request, 'Templates feature requires Standard edition or higher.');
		}

		$result = $this->queryService->query($collection, $params);

		// Render each item through the Twig template
		$html = '';
		foreach ($result->items as $item) {
			$html .= $this->twigEngine->render($template . '.html', ['object' => $item]);
		}

		// Append HTMX trigger for next page
		$html    .= $this->htmxRenderer->buildNextPageTrigger($collection, $result, $params);
		$response = $result->withPaginationHeaders($response);
		$response = $response->withHeader('Content-Type', 'text/html');

		return $this->rawRenderer->render($response, $html);
	}

	/**
	 * @param array<string,string> $params
	 */
	private function renderCsv(
		ResponseInterface $response,
		string $collection,
		array $params,
	): ResponseInterface {
		$result   = $this->queryService->query($collection, $params);
		$response = $result->withPaginationHeaders($response);

		$csv = Writer::fromString('');
		if ($result->items !== []) {
			// Use the first item's keys as headers
			$csv->insertOne(array_keys($result->items[0]));
			foreach ($result->items as $item) {
				$csv->insertOne(array_map(
					static function (mixed $value): string {
						if (is_array($value)) {
							return (string)json_encode($value);
						}
						if (is_bool($value)) {
							return $value ? 'true' : 'false';
						}

						return (string)$value;
					},
					$item,
				));
			}
		}

		$response = $response->withHeader('Content-Type', 'text/csv')
			->withHeader('Content-Disposition', sprintf('attachment; filename="collection-%s.csv"', $collection));

		return $response->withBody(Stream::create($csv->toString()));
	}
}
