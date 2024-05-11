<?php

namespace TotalCMS\Action\Schema;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\SchemaMetaTransformer;

final class SchemaSaveAction
{
    private JsonRenderer $renderer;
    private SchemaSaver $service;

    /**
     * The constructor.
     *
     * @param JsonRenderer $renderer The renderer
     * @param SchemaSaver $service Schema save service
     */
    public function __construct(JsonRenderer $renderer, SchemaSaver $service)
    {
        $this->renderer = $renderer;
        $this->service  = $service;
    }

    /**
     * Invokable Action.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param array $args The routing arguments
     *
     * @return ResponseInterface
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $data = json_decode($request->getBody(), true);

        return $this->renderer->jsonItem($response, $this->service->saveSchema($data), new SchemaMetaTransformer());
    }
}
