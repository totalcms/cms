<?php

namespace TotalCMS\Action\Schema;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\SchemaMetaTransformer;

final readonly class SchemaFetchForCollectionAction
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
		$schema = $this->schemaFetcher->fetchSchemaForCollection($args['collection']);

		return $this->renderer->jsonItem($response, $schema, new SchemaMetaTransformer());
	}
}
