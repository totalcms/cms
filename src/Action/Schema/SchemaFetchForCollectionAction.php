<?php

namespace TotalCMS\Action\Schema;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Schema\Service\CollectionSchemaFetcher;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\SchemaMetaTransformer;

final class SchemaFetchForCollectionAction
{
	private JsonRenderer $renderer;
	private CollectionSchemaFetcher $schemaFetcher;

	public function __construct(JsonRenderer $renderer, CollectionSchemaFetcher $service)
	{
		$this->renderer      = $renderer;
		$this->schemaFetcher = $service;
	}

	/**
	 * Action.
	 *
	 * @param ServerRequestInterface $request
	 * @param ResponseInterface $response
	 * @param array<string,string> $args The routing arguments
	 *
	 * @return ResponseInterface the response
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$schema = $this->schemaFetcher->fetchSchemaForCollection($args['collection']);

		return $this->renderer->jsonItem($response, $schema, new SchemaMetaTransformer());
	}
}
