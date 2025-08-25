<?php

namespace TotalCMS\Action\Schema;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\SchemaMetaTransformer;

readonly class SchemaListAction
{
	public function __construct(private JsonRenderer $renderer, private SchemaLister $schemaLister)
	{
	}

	/**
	 * Action.
	 *
	 * @return ResponseInterface the response
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$params = $request->getQueryParams();
		$filter = $params['filter'] ?? 'all';

		$schemas = match ($filter) {
			'reserved' => $this->schemaLister->listReservedSchemas(),
			'custom'   => $this->schemaLister->listCustomSchemas(),
			default    => $this->schemaLister->listAllSchemas(),
		};

		return $this->renderer->jsonCollection($response, $schemas, new SchemaMetaTransformer());
	}
}
