<?php

namespace TotalCMS\Action\Schema;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\SchemaMetaTransformer;

final class SchemaListAction
{
    private JsonRenderer $renderer;
    private SchemaLister $schemaLister;

    public function __construct(JsonRenderer $renderer, SchemaLister $service)
    {
        $this->renderer     = $renderer;
        $this->schemaLister = $service;
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
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $params = $request->getQueryParams();
        $filter = $params['filter'] ?? 'all';

        switch ($filter) {
            case 'reserved':
                $schemas = $this->schemaLister->listReservedSchemas();
                break;
            case 'custom':
                $schemas = $this->schemaLister->listCustomSchemas();
                break;
            default:
                $schemas = $this->schemaLister->listAllSchemas();
        }

        return $this->renderer->jsonCollection($response, $schemas, new SchemaMetaTransformer());
    }
}
