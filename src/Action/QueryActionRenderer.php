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
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Query\Data\QueryResult;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
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
		private CollectionFetcher $collectionFetcher,
		private SchemaFetcher $schemaFetcher,
		private ObjectUrlBuilder $objectUrlBuilder,
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

		$html = '';
		foreach ($result->items as $item) {
			$html .= $this->twigEngine->render($template . '.html', ['object' => $item]);
		}

		$html .= $this->htmxRenderer->buildNextPageTrigger($baseUrl, $result, $params);
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
		$collection = $params['_collection'] ?? '';
		if ($collection === '') {
			throw new HttpBadRequestException($request, 'The "_collection" parameter is required for table format.');
		}

		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if ($collectionData === null) {
			throw new HttpBadRequestException($request, "Collection '{$collection}' not found.");
		}

		$schemaData    = $this->schemaFetcher->fetchSchema($collectionData->schema);
		$labelSingular = $collectionData->labelSingular !== '' ? $collectionData->labelSingular : 'Object';
		$collectionUrl = $collectionData->url;

		// Build columns array from schema index
		$columns = [];
		foreach ($schemaData->index as $property) {
			$columns[] = [
				'name' => $property,
				'type' => $this->getPropertyType($schemaData, $property),
			];
		}

		$api  = $params['_api'] ?? '';
		$html = '';
		foreach ($result->items as $item) {
			$objectUrl = '';
			if ($collectionUrl !== '') {
				$objectUrl = $this->objectUrlBuilder->buildUrl($collectionData, $item);
			}

			$html .= $this->twigEngine->render('admin/collection/table-row.html', [
				'object'         => $item,
				'_collection'    => $collection,
				'_columns'       => $columns,
				'_api'           => $api,
				'_labelSingular' => $labelSingular,
				'_collectionUrl' => $collectionUrl,
				'_objectUrl'     => $objectUrl,
			]);
		}

		// Build sentinel <tr> for next page if there are more results
		if ($result->hasMore()) {
			$nextParams             = $params;
			$nextParams['format']   = 'table';
			$nextParams['offset']   = (string)$result->nextOffset();
			$nextParams['limit']    = (string)$result->limit;
			$sentinelUrl            = $baseUrl . '?' . http_build_query($nextParams);
			$colspan                = (string)(count($columns) + 1);
			$html                  .= '<tr class="htmx-sentinel" hx-get="' . htmlspecialchars($sentinelUrl) . '" hx-trigger="revealed" hx-swap="outerHTML">';
			$html                  .= '<td colspan="' . $colspan . '"><span class="loading-dots"></span></td>';
			$html                  .= '</tr>';
		}

		$response = $result->withPaginationHeaders($response);
		$response = $response->withHeader('Content-Type', 'text/html');

		return $this->rawRenderer->render($response, $html);
	}

	/**
	 * Determine the property type from schema definition.
	 */
	private function getPropertyType(
		SchemaData $schemaData,
		string $property,
	): string {
		if (isset($schemaData->properties[$property])) {
			$propertyData = $schemaData->properties[$property];
			if (isset($propertyData['type'])) {
				return $propertyData['type'];
			}
			if (isset($propertyData['$ref'])) {
				return basename((string)$propertyData['$ref'], '.json');
			}
		}

		return 'string';
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
