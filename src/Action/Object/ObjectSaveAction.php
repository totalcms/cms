<?php

namespace App\Action\Object;

use App\Domain\Object\Service\ObjectSaver;
use App\Renderer\JsonRenderer;
use App\Transformer\ObjectMetaTransformer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ObjectSaveAction
{
    private JsonRenderer $renderer;
    private ObjectSaver $service;

    /**
     * The constructor.
     *
     * @param JsonRenderer $renderer The renderer
     * @param ObjectSaver $service Object save service
     */
    public function __construct(JsonRenderer $renderer, ObjectSaver $service)
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
            $this->service->saveObject($args['collection'], $body),
            new ObjectMetaTransformer()
        );
    }
}
