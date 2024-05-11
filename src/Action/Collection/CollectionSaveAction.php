<?php

namespace TotalCMS\Action\Collection;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\CollectionMetaTransformer;

final class CollectionSaveAction
{
    private JsonRenderer $renderer;
    private CollectionSaver $service;

    /**
     * The constructor.
     *
     * @param JsonRenderer $renderer The renderer
     * @param CollectionSaver $service Collection save service
     */
    public function __construct(JsonRenderer $renderer, CollectionSaver $service)
    {
        $this->renderer = $renderer;
        $this->service  = $service;
    }

    /**
     * Action.
     *
     * @param ServerRequestInterface $request The request
     * @param ResponseInterface $response The response
     *
     * @return ResponseInterface The response
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = json_decode($request->getBody(), true);

        return $this->renderer->jsonItem(
            $response,
            $this->service->saveCollection($data),
            new CollectionMetaTransformer()
        );
    }
}
