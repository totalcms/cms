<?php

namespace App\Action\Schema;

use App\Domain\Schema\Service\SchemaSaver;
use App\Renderer\JsonRenderer;
use App\Transformer\SchemaMetaTransformer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

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
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $body = (string)$request->getBody();

        return $this->renderer->jsonItem(
            $response,
            $this->service->saveSchema($body),
            new SchemaMetaTransformer()
        );
    }
}
