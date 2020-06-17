<?php

namespace App\Action\Collection;

use App\Domain\Collection\Service\CollectionListService;
use App\Responder\Responder;
use App\Transformer\CollectionMetaTransformer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;

final class CollectionListAction
{
    private Responder $responder;
    private CollectionListService $collectionListService;

    /**
     * The constructor
     *
     * @param CollectionListService $service   collection list service
     * @param Responder             $responder The app responder
     */
    public function __construct(Responder $responder, CollectionListService $service)
    {
        // $this->logger = $loggerFactory->createInstance((new ReflectionClass($this))->getShortName());
        $this->responder             = $responder;
        $this->collectionListService = $service;
    }

    /**
     * Invokable Action
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface      $response
     *
     * @return ResponseInterface
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response) : ResponseInterface
    {
        // $this->logger->info('Hello from CollectionListAction');
        return $this->responder->jsonCollection(
            $response,
            $this->collectionListService->listAllCollections(),
            new CollectionMetaTransformer()
        );
    }
}
