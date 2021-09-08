<?php

namespace App\Action\Collection\Schema;

use App\Domain\Schema\Service\SchemaSaver;
use App\Responder\Responder;
use App\Transformer\SchemaMetaTransformer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class SchemaSaveAction
{
    private Responder $responder;
    private SchemaSaver $service;

    /**
     * The constructor.
     *
     * @param Responder $responder The app responder
     * @param SchemaSaver $service Schema save service
     */
    public function __construct(Responder $responder, SchemaSaver $service)
    {
        $this->responder = $responder;
        $this->service = $service;
    }

    /**
     * Invokable Action.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param array $args The routing arguments
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
            $this->service->saveSchemaForCollection($args['collection'], $body),
            new SchemaMetaTransformer()
        );
    }
}
