<?php

namespace TotalCMS\Action\Collection\Index;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\IndexTransformer;

readonly class IndexGetAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private IndexReader $service,
		private IndexFilter $indexFilter,
	) {
	}

	/**
	 * Action.
	 *
	 * @param ServerRequestInterface $request The request
	 * @param ResponseInterface $response The response
	 * @param array<string,string> $args The routing arguments
	 *
	 * @return ResponseInterface The response
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		// Get query parameters
		$params = $request->getQueryParams();

		// Extract filter options
		$filterOptions = $this->indexFilter->extractFilterOptions($params);

		// Fetch and filter index
		if ($filterOptions !== []) {
			$index = $this->indexFilter->fetchFilteredIndexData($args['collection'], $filterOptions);
		} else {
			$index = $this->service->fetchIndex($args['collection']);
		}

		return $this->renderer->jsonItem($response, $index, new IndexTransformer());
	}
}
