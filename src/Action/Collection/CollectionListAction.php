<?php

namespace App\Action\Collection;

use App\Domain\Collection\Service\CollectionFinder;
use App\Renderer\JsonRenderer;
use App\Transformer\CollectionMetaTransformer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CollectionListAction
{
    private JsonRenderer $renderer;
    private CollectionFinder $collectionListService;

    /**
     * The constructor.
     *
     * @param JsonRenderer $renderer The renderer
     * @param CollectionFinder $service The service
     */
    public function __construct(JsonRenderer $renderer, CollectionFinder $service)
    {
        $this->renderer = $renderer;
        $this->collectionListService = $service;
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
        return $this->renderer->jsonCollection(
            $response,
            $this->collectionListService->listAllCollections(),
            new CollectionMetaTransformer()
        );
    }
}
