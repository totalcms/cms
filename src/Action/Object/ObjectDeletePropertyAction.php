<?php

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

final class ObjectDeletePropertyAction
{
    public function __construct(
        private JsonRenderer $renderer,
        private ObjectSaver $service
    ) {
        $this->renderer = $renderer;
        $this->service  = $service;
    }

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $object = $this->service->deleteObjectProperty($args['collection'], $args['id'], $args['property']);

        return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
    }
}
