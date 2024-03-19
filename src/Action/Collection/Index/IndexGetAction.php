<?php

namespace TotalCMS\Action\Collection\Index;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\IndexTransformer;

final class IndexGetAction
{
    private JsonRenderer $renderer;
    private IndexReader $service;

    /**
     * The constructor.
     *
     * @param JsonRenderer $renderer The renderer
     * @param IndexReader $service The service
     */
    public function __construct(JsonRenderer $renderer, IndexReader $service)
    {
        $this->renderer = $renderer;
        $this->service  = $service;
    }

    /**
     * Action.
     *
     * @param ServerRequestInterface $request The request
     * @param ResponseInterface $response The response
     * @param array $args The routing arguments
     *
     * @return ResponseInterface The response
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        return $this->renderer->jsonItem(
            $response,
            $this->service->fetchIndex($args['collection']),
            new IndexTransformer()
        );
    }
}
