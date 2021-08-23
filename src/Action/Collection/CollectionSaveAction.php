<?php

namespace App\Action\Collection;

use App\Domain\Collection\Service\CollectionSaveService;
use App\Responder\Responder;
use App\Transformer\CollectionMetaTransformer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CollectionSaveAction
{
    private Responder $responder;
    private CollectionSaveService $service;

    /**
     * The constructor.
     *
     * @param Responder             $responder The app responder
     * @param CollectionSaveService $service   Collection save service
     */
    public function __construct(Responder $responder, CollectionSaveService $service)
    {
        $this->responder = $responder;
        $this->service = $service;
    }

    /**
     * Invokable Action.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface      $response
     *
     * @return ResponseInterface
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getBody();

        return $this->responder->jsonItem(
            $response,
            $this->service->saveCollection($body),
            new CollectionMetaTransformer()
        );
    }
}
