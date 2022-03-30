<?php

namespace App\Action\Collection;

use App\Domain\Collection\Service\CollectionCreator;
use App\Renderer\JsonRenderer;
use App\Transformer\CollectionMetaTransformer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CollectionSaveAction
{
    private JsonRenderer $renderer;
    private CollectionCreator $service;

    /**
     * The constructor.
     *
     * @param JsonRenderer $renderer The renderer
     * @param CollectionCreator $service Collection save service
     */
    public function __construct(JsonRenderer $renderer, CollectionCreator $service)
    {
        $this->renderer = $renderer;
        $this->service = $service;
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
        $body = $request->getBody();

        return $this->renderer->jsonItem(
            $response,
            $this->service->saveCollection($body),
            new CollectionMetaTransformer()
        );
    }
}
