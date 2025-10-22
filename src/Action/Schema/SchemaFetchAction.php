<?php

namespace TotalCMS\Action\Schema;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\SchemaMetaTransformer;

readonly class SchemaFetchAction
{
	public function __construct(private JsonRenderer $renderer, private SchemaFetcher $schemaFetcher)
	{
	}

	/**
	 * Action.
	 *
	 * @param array<string,string> $args The routing arguments
	 *
	 * @return ResponseInterface the response
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		// Get raw parameter from query string (default: false, returns flattened)
		$queryParams = $request->getQueryParams();
		$raw         = isset($queryParams['raw']) && $queryParams['raw'] === 'true';

		$schema = $raw
			? $this->schemaFetcher->fetchRawSchema($args['id'])
			: $this->schemaFetcher->fetchSchema($args['id']);

		return $this->renderer->jsonItem($response, $schema, new SchemaMetaTransformer());
	}
}
