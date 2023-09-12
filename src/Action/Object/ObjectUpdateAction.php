<?php

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

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
        $data   = json_decode($request->getBody(), true);
        $object = $this->service->updateObject($args['collection'], $args['id'], $data);

        return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
    }
}
