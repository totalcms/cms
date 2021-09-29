<?php

namespace App\Action\Collection\Object;

use App\Domain\Object\Service\ObjectSaver;
use App\Responder\Responder;
use App\Transformer\ObjectMetaTransformer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ObjectSaveAction
{
    private Responder $responder;
    private ObjectSaver $service;

    /**
     * The constructor.
     *
     * @param Responder $responder The app responder
     * @param ObjectSaver $service Object save service
     */
    public function __construct(Responder $responder, ObjectSaver $service)
    {
        $this->responder = $responder;
        $this->service = $service;
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

        return $this->responder->jsonItem(
            $response,
            $this->service->saveObject($args['collection'], $body),
            new ObjectMetaTransformer()
        );
    }
}
