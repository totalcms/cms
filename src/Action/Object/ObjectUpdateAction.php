<?php

namespace TotalCMS\Action\Object;

use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ObjectUpdateAction
{
    private JsonRenderer $renderer;
    private ObjectUpdater $service;

    /**
     * The constructor.
     *
     * @param JsonRenderer $renderer The renderer
     * @param ObjectUpdater $service Object save service
     */
    public function __construct(JsonRenderer $renderer, ObjectUpdater $service)
    {
        $this->renderer = $renderer;
        $this->service  = $service;
    }

    /**
     * Action.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param array $args
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
            $this->service->updateObject($args['collection'], $args['id'], $body),
            new ObjectMetaTransformer()
        );
    }
}
