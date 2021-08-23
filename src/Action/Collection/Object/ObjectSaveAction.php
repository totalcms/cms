<?php

namespace App\Action\Collection\Object;

use App\Domain\Object\Service\ObjectSaveService;
use App\Responder\Responder;
use App\Transformer\ObjectMetaTransformer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ObjectSaveAction
{
    private Responder $responder;
    private ObjectSaveService $service;

    /**
     * The constructor.
     *
     * @param Responder         $responder The app responder
     * @param ObjectSaveService $service   Object save service
     */
    public function __construct(Responder $responder, ObjectSaveService $service)
    {
        $this->responder = $responder;
        $this->service = $service;
    }

    /**
     * Action.
     *
     * @param  ServerRequestInterface $request
     * @param  ResponseInterface      $response
     * @param  mixed[]                $args
     *
     * @return ResponseInterface
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = $request->getBody();

        return $this->responder->jsonItem(
            $response,
            $this->service->saveObject($args['collection'], $body),
            new ObjectMetaTransformer()
        );
    }
}
