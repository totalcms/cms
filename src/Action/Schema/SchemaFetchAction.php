<?php

namespace App\Action\Schema;

use App\Domain\Schema\Service\SchemaFetcher;
use App\Renderer\JsonRenderer;
use App\Transformer\SchemaMetaTransformer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class SchemaFetchAction
{
    private JsonRenderer $renderer;
    private SchemaFetcher $schemaFetcher;

    public function __construct(JsonRenderer $renderer, SchemaFetcher $service)
    {
        $this->renderer = $renderer;
        $this->schemaFetcher = $service;
    }

    /**
     * Action.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param array $args The routing arguments
     *
     * @return ResponseInterface the response
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $schema = $this->schemaFetcher->fetchSchemaForCollection($args['collection']);

        return $this->renderer->jsonItem($response, $schema, new SchemaMetaTransformer());
    }
}
