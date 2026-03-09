<?php

declare(strict_types=1);

namespace TotalCMS\Action;

use League\Csv\Writer;
use League\Fractal\Manager as FractalManager;
use League\Fractal\Resource\Collection as FractalCollection;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use TotalCMS\Domain\Admin\AdminTableRenderer;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Query\Data\QueryResult;
use TotalCMS\Domain\Twig\Service\HtmxRenderer;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\OffsetPaginator;
use TotalCMS\Renderer\RawRenderer;
use TotalCMS\Transformer\QueryObjectTransformer;

/**
 * Shared rendering logic for paginated query endpoints.
 *
 * Handles JSON, HTML, and CSV output formats for both
 * collection queries and DataView queries.
 */
readonly class QueryActionRenderer
{
	public function __construct(
		private JsonRenderer $jsonRenderer,
		private RawRenderer $rawRenderer,
		private HtmxRenderer $htmxRenderer,
		private TwigEngine $twigEngine,
		private EditionFeatureService $editionFeatures,
		private AdminTableRenderer $adminTableRenderer,
	) {
	}

	/**
	 * Render a query result in the requested format.
	 *
	 * @param array<string,string> $params      Query parameters
	 * @param string               $baseUrl     Full base URL for pagination links
	 * @param string               $csvFilename Filename for CSV downloads (without extension)
	 */
	public function render(
		ServerRequestInterface $request,
		ResponseInterface $response,
		QueryResult $result,
		array $params,
		string $format,
		string $baseUrl,
		string $csvFilename,
	): ResponseInterface {
		return match ($format) {
			'html'  => $this->renderHtml($request, $response, $result, $params, $baseUrl),
			'table' => $this->renderTable($request, $response, $result, $params, $baseUrl),
			'csv'   => $this->renderCsv($response, $result, $csvFilename),
			default => $this->renderJson($response, $result, $baseUrl),
		};
	}

	private function renderJson(
		ResponseInterface $response,
		QueryResult $result,
		string $baseUrl,
	): ResponseInterface {
		$resource  = new FractalCollection($result->items, new QueryObjectTransformer());
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
		QueryResult $result,
		array $params,
		string $baseUrl,
	): ResponseInterface {
		$template = $params['template'] ?? '';
		if ($template === '') {
			throw new HttpBadRequestException($request, 'The "template" parameter is required for HTML format.');
		}

		if (!$this->editionFeatures->can(EditionFeature::TEMPLATES)) {
			throw new HttpForbiddenException($request, 'Templates feature requires Standard edition or higher.');
		}

		$context = [];
		if (isset($params['_collection'])) {
			$context['collection'] = $params['_collection'];
		}

		$html = '';
		foreach ($result->items as $item) {
			$html .= $this->twigEngine->render($template . '.twig', ['object' => $item] + $context);
		}

		$mode = $params['mode'] ?? '';
		if ($mode === 'append') {
			$html .= $this->htmxRenderer->buildOobButton($baseUrl, $result, $params);
		} else {
			$html .= $this->htmxRenderer->buildNextPageTrigger($baseUrl, $result, $params);
		}
		$response = $result->withPaginationHeaders($response);
		$response = $response->withHeader('Content-Type', 'text/html');

		return $this->rawRenderer->render($response, $html);
	}

	/**
	 * Render query results as admin table rows (internal template, no edition check).
	 *
	 * @param array<string,string> $params
	 */
	private function renderTable(
		ServerRequestInterface $request,
		ResponseInterface $response,
		QueryResult $result,
		array $params,
		string $baseUrl,
	): ResponseInterface {
		try {
			$html = $this->adminTableRenderer->render($result, $params, $baseUrl);
		} catch (\RuntimeException $e) {
			throw new HttpBadRequestException($request, $e->getMessage());
		}

		$response = $result->withPaginationHeaders($response);
		$response = $response->withHeader('Content-Type', 'text/html');

		return $this->rawRenderer->render($response, $html);
	}

	private function renderCsv(
		ResponseInterface $response,
		QueryResult $result,
		string $csvFilename,
	): ResponseInterface {
		$response = $result->withPaginationHeaders($response);

		$csv = Writer::fromString('');
		if ($result->items !== []) {
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
			->withHeader('Content-Disposition', sprintf('attachment; filename="%s.csv"', $csvFilename));

		return $response->withBody(Stream::create($csv->toString()));
	}
}
