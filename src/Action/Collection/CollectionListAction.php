<?php

namespace App\Action\Collection;

use App\Domain\Collection\Service\CollectionFinder;
use App\Responder\Responder;
use App\Transformer\CollectionMetaTransformer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CollectionListAction
{
    private Responder $responder;
    private CollectionFinder $collectionListService;

    /**
     * The constructor.
     *
     * @param Responder $responder The responder
     * @param CollectionFinder $service The service
     */
    public function __construct(Responder $responder, CollectionFinder $service)
    {
        $this->responder = $responder;
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
        return $this->responder->jsonCollection(
            $response,
            $this->collectionListService->listAllCollections(),
            new CollectionMetaTransformer()
        );
    }
}
